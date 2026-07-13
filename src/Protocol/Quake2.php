<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Server;

/**
 * id Tech 2 "status" query (UDP), the protocol behind Quake 2 and close
 * derivatives (Quake 2 Remastered, various Q2 mods and source ports).
 *
 * Conversation shape (single UDP request/response):
 *   -> \xFF\xFF\xFF\xFF status
 *   <- \xFF\xFF\xFF\xFF print\n \key\value\key\value...\n
 *        <frags> <ping> "<name>"\n  (one line per player)
 *
 * Structurally the same as the id Tech 3 getstatus reply, but the reply is
 * introduced by "print" instead of "statusResponse" and the server-info
 * cvars use the older unprefixed names (hostname, mapname, maxclients).
 */
final class Quake2 extends AbstractProtocol
{
    private const OOB = "\xFF\xFF\xFF\xFF";

    public static function name(): string
    {
        return 'quake2';
    }

    public function transport(): string
    {
        return 'udp';
    }

    public function initialStep(Server $server): array
    {
        return ['tag' => 'status', 'packet' => self::OOB . "status\x0A"];
    }

    public function nextStep(Server $server, array $history): ?array
    {
        return null;
    }

    public function parse(Server $server, array $history): array
    {
        $raw = $this->responseFor($history, 'status');
        if ($raw === null) {
            return [];
        }

        $body = ltrim($raw, "\xFF");
        $lines = explode("\n", $body);
        array_shift($lines); // "print"

        $cvars = $this->parseInfoString($lines[0] ?? '');

        $players = [];
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(-?\d+)\s+(\d+)\s+"(.*)"$/', $line, $m)) {
                $players[] = ['frags' => (int) $m[1], 'ping' => (int) $m[2], 'name' => $m[3]];
            }
        }

        $result = [
            'name' => $cvars['hostname'] ?? 'Quake2 Server',
            'map' => $cvars['mapname'] ?? null,
            'max_players' => isset($cvars['maxclients']) ? (int) $cvars['maxclients'] : 0,
            'players' => count($players),
            'players_list' => array_map(static fn ($p) => $p['name'], $players),
            'rules' => $cvars,
        ];
        if (isset($cvars['gamename'])) {
            $result['game'] = $cvars['gamename'];
        }

        return $result;
    }

    private function parseInfoString(string $s): array
    {
        $parts = explode('\\', ltrim($s, '\\'));
        $cvars = [];
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $cvars[$parts[$i]] = $parts[$i + 1];
        }

        return $cvars;
    }
}
