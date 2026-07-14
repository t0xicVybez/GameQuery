<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Server;

/**
 * QuakeWorld "status" query (UDP) — the original Quake 1 netcode, still spoken
 * by modern QuakeWorld ports (ezQuake, FTE, nQuake) and games built on them.
 * Distinct from the later id Tech 2/3 status protocols: the response marker is
 * a single 'n', the info string keys are unprefixed (hostname, map, maxclients
 * rather than sv_hostname / sv_maxclients), and player lines carry the extra
 * QuakeWorld fields (userid, frags, time, name, skin, colors).
 *
 * Conversation shape (single UDP request/response):
 *   -> \xFF\xFF\xFF\xFF status\n
 *   <- \xFF\xFF\xFF\xFF n \key\value...\n
 *        <userid> <frags> <time> "<name>" "<skin>" <top> <bottom>\n  (per player)
 */
final class QuakeWorld extends AbstractProtocol
{
    private const OOB = "\xFF\xFF\xFF\xFF";

    public static function name(): string
    {
        return 'quakeworld';
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
        return null; // single-shot
    }

    public function parse(Server $server, array $history): array
    {
        $raw = $this->responseFor($history, 'status');
        if ($raw === null) {
            return [];
        }

        // Drop the 0xFF out-of-band header and the leading 'n' response marker.
        $body = ltrim($raw, "\xFF");
        if (isset($body[0]) && $body[0] === 'n') {
            $body = substr($body, 1);
        }

        $lines = explode("\n", $body);
        $cvars = $this->parseInfoString($lines[0] ?? '');

        $players = [];
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }
            // <userid> <frags> <time> "name" "skin" <top> <bottom>
            if (preg_match('/^\d+\s+(-?\d+)\s+\d+\s+"([^"]*)"/', $line, $m)) {
                $players[] = ['frags' => (int) $m[1], 'name' => $m[2]];
            }
        }

        $result = [
            'name' => $cvars['hostname'] ?? 'QuakeWorld Server',
            'map' => $cvars['map'] ?? null,
            'max_players' => isset($cvars['maxclients']) ? (int) $cvars['maxclients'] : 0,
            'players' => count($players),
            'players_list' => array_map(static fn ($p) => $p['name'], $players),
            'rules' => $cvars,
        ];
        if (isset($cvars['*gamedir'])) {
            $result['game'] = $cvars['*gamedir'];
        }
        if (isset($cvars['*version'])) {
            $result['version'] = $cvars['*version'];
        }

        return $result;
    }

    /** Parse a `\key\value\key\value` id Tech info string into a map. */
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
