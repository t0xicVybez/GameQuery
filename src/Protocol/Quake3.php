<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Server;

/**
 * id Tech 3 "getstatus" query (UDP), the protocol behind the Quake 3 engine
 * and its many descendants: Quake 3 / Live, all of Call of Duty 1, 2, 4, UO
 * and World at War, OpenArena, Xonotic, Warsow, Urban Terror, Wolfenstein:
 * Enemy Territory, Jedi Academy, Soldier of Fortune 2, and more.
 *
 * Conversation shape (single UDP request/response):
 *   -> \xFF\xFF\xFF\xFF getstatus
 *   <- \xFF\xFF\xFF\xFF statusResponse\n \key\value\key\value...\n
 *        <score> <ping> "<name>"\n  (one line per player)
 *
 * The leading four 0xFF bytes are the id Tech "out of band" marker. The
 * first line after it is a backslash-delimited cvar string (sv_hostname,
 * mapname, sv_maxclients, g_gametype, ...); the remaining lines are players.
 */
final class Quake3 extends AbstractProtocol
{
    private const OOB = "\xFF\xFF\xFF\xFF";

    public static function name(): string
    {
        return 'quake3';
    }

    public function transport(): string
    {
        return 'udp';
    }

    public function initialStep(Server $server): array
    {
        return ['tag' => 'status', 'packet' => self::OOB . "getstatus\x0A"];
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

        // Drop the 0xFF out-of-band header and the "statusResponse" line.
        $body = ltrim($raw, "\xFF");
        $lines = explode("\n", $body);
        array_shift($lines); // "statusResponse"

        $infoString = $lines[0] ?? '';
        $cvars = $this->parseInfoString($infoString);

        $players = [];
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(-?\d+)\s+(\d+)\s+"(.*)"$/', $line, $m)) {
                $players[] = ['score' => (int) $m[1], 'ping' => (int) $m[2], 'name' => $m[3]];
            }
        }

        $result = [
            'name' => $cvars['sv_hostname'] ?? 'Quake3 Server',
            'map' => $cvars['mapname'] ?? null,
            'max_players' => isset($cvars['sv_maxclients']) ? (int) $cvars['sv_maxclients'] : 0,
            'players' => count($players),
            'players_list' => array_map(static fn ($p) => $p['name'], $players),
            'password_protected' => isset($cvars['g_needpass']) ? (bool) (int) $cvars['g_needpass'] : false,
        ];
        if (isset($cvars['gamename'])) {
            $result['game'] = $cvars['gamename'];
        }
        if (isset($cvars['g_gametype'])) {
            $result['gametype'] = $cvars['g_gametype'];
        }
        $result['rules'] = $cvars;

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
