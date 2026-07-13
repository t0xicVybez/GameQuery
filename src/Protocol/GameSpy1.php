<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Server;

/**
 * GameSpy protocol version 1 (UDP) — the original text-based query used by
 * late-90s/early-2000s titles: Unreal, Unreal Tournament (GOTY/99), Deus Ex,
 * Tribes 2, Serious Sam, and other pre-GameSpy2 games.
 *
 * Conversation shape (single UDP request/response):
 *   -> \status\
 *   <- \hostname\..\mapname\..\numplayers\..\maxplayers\..\player_0\..\final\
 *
 * The reply is one flat backslash-delimited key/value string. Server fields
 * and per-player fields (player_0, frags_0, ping_0, ...) share the same
 * namespace; players are reassembled by their numeric suffix.
 *
 * Known limitation: long rosters split across multiple UDP packets (each
 * tagged with a trailing \queryid\<id>.<n>) — this reads the first datagram
 * only, so very full servers may report a partial player list.
 */
final class GameSpy1 extends AbstractProtocol
{
    public static function name(): string
    {
        return 'gamespy1';
    }

    public function transport(): string
    {
        return 'udp';
    }

    public function initialStep(Server $server): array
    {
        return ['tag' => 'status', 'packet' => '\\status\\'];
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

        $parts = explode('\\', ltrim($raw, '\\'));

        $cvars = [];
        $players = [];
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $key = $parts[$i];
            $value = $parts[$i + 1];

            if ($key === 'final' || $key === 'queryid') {
                continue;
            }

            if (preg_match('/^player_(\d+)$/', $key, $m)) {
                $players[(int) $m[1]] = $value;
                continue;
            }

            $cvars[$key] = $value;
        }

        ksort($players);
        $playersList = array_values($players);

        $numPlayers = isset($cvars['numplayers']) ? (int) $cvars['numplayers'] : count($playersList);

        $result = [
            'name' => $cvars['hostname'] ?? 'GameSpy Server',
            'map' => $cvars['mapname'] ?? null,
            'max_players' => isset($cvars['maxplayers']) ? (int) $cvars['maxplayers'] : 0,
            'players' => $numPlayers,
            'players_list' => $playersList,
            'password_protected' => isset($cvars['password']) ? (bool) (int) $cvars['password'] : false,
            'rules' => $cvars,
        ];
        if (isset($cvars['gametype'])) {
            $result['gametype'] = $cvars['gametype'];
        }
        if (isset($cvars['gamever'])) {
            $result['version'] = $cvars['gamever'];
        }

        return $result;
    }
}
