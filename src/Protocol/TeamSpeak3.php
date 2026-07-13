<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Server;

/**
 * TeamSpeak 3 (and TeaSpeak) via the ServerQuery text interface (TCP, default
 * port 10011 — connect to that, not the voice port). ServerQuery is a
 * telnet-style, newline-delimited protocol: the server greets with "TS3", then
 * answers whitespace key=value command replies terminated by an
 * "error id=<n> msg=<...>" line.
 *
 * We select the virtual server by its voice port and read its serverinfo in a
 * single write ("use" then "serverinfo" then "quit"); the server closes after
 * "quit". Pass the voice port as options['voicePort'] (default 9987).
 *
 * Note: ServerQuery is bound to localhost only on a default install, so remote
 * queries require the admin to have opened query_port externally.
 */
final class TeamSpeak3 extends AbstractProtocol
{
    public static function name(): string
    {
        return 'teamspeak3';
    }

    public function transport(): string
    {
        return 'tcp';
    }

    public function initialStep(Server $server): array
    {
        $voicePort = (int) ($server->options['voicePort'] ?? 9987);

        return ['tag' => 'query', 'packet' => "use port={$voicePort}\nserverinfo\nquit\n"];
    }

    public function nextStep(Server $server, array $history): ?array
    {
        return null;
    }

    public function isResponseComplete(string $buffer): bool
    {
        // One "error id=" line per command; serverinfo is done once both the
        // "use" and "serverinfo" replies have landed (the server also closes
        // the connection after "quit", which finalises as a fallback).
        return substr_count($buffer, 'error id=') >= 2;
    }

    public function parse(Server $server, array $history): array
    {
        $raw = $this->responseFor($history, 'query');
        if ($raw === null) {
            return [];
        }

        $line = null;
        foreach (preg_split('/\r?\n/', $raw) as $candidate) {
            if (str_contains($candidate, 'virtualserver_name=')) {
                $line = trim($candidate);
                break;
            }
        }
        if ($line === null) {
            return [];
        }

        $fields = [];
        foreach (explode(' ', $line) as $token) {
            if (!str_contains($token, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $token, 2);
            $fields[$key] = $this->unescape($value);
        }

        $clients = (int) ($fields['virtualserver_clientsonline'] ?? 0);
        $queryClients = (int) ($fields['virtualserver_queryclientsonline'] ?? 0);

        return [
            'name' => $fields['virtualserver_name'] ?? 'TeamSpeak 3 Server',
            'players' => max(0, $clients - $queryClients), // exclude ServerQuery connections
            'max_players' => (int) ($fields['virtualserver_maxclients'] ?? 0),
            'version' => $fields['virtualserver_version'] ?? null,
            'players_list' => [],
        ];
    }

    /** Decode TeamSpeak's backslash escaping (\s = space, \p = pipe, etc.). */
    private function unescape(string $value): string
    {
        return strtr($value, [
            '\\\\' => '\\',
            '\\/' => '/',
            '\\s' => ' ',
            '\\p' => '|',
            '\\a' => "\x07",
            '\\b' => "\x08",
            '\\f' => "\x0C",
            '\\n' => "\x0A",
            '\\r' => "\x0D",
            '\\t' => "\x09",
            '\\v' => "\x0B",
        ]);
    }
}
