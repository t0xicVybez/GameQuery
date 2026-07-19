<?php

declare(strict_types=1);

namespace GameQuery;

/**
 * Outcome of querying a single server. Always returned, even on timeout --
 * check `online` before trusting the rest of the fields.
 *
 * `data` holds the raw, protocol-specific parse output (field names vary by
 * game). For the fields common to almost every protocol, prefer the normalized
 * accessors -- name(), map(), players(), maxPlayers(), playerNames() -- which
 * read the right key regardless of protocol and are stable across releases.
 */
final class Result
{
    public function __construct(
        public readonly Server $server,
        public readonly bool $online,
        public readonly float $pingMs,
        /** @var array<string,mixed> Protocol-specific parsed data (name, map, players, etc.) */
        public readonly array $data = [],
        public readonly ?string $error = null,
        /** One of ErrorCode::* when offline/errored; null when online and clean. */
        public readonly ?string $errorCode = null,
    ) {
    }

    /** Server/host name as reported by the game, or null if this protocol doesn't provide one. */
    public function name(): ?string
    {
        $v = $this->data['name'] ?? null;
        return is_string($v) ? $v : null;
    }

    /** Current map/level, or null if not applicable to this protocol. */
    public function map(): ?string
    {
        $v = $this->data['map'] ?? null;
        return is_string($v) ? $v : null;
    }

    /** Current player count, or null if unknown. */
    public function players(): ?int
    {
        $v = $this->data['players'] ?? null;
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : null);
    }

    /** Maximum player slots, or null if unknown. */
    public function maxPlayers(): ?int
    {
        $v = $this->data['max_players'] ?? null;
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : null);
    }

    /**
     * Connected player names, normalized to a flat list of strings regardless of
     * whether the protocol stored rich player objects or bare names.
     *
     * @return list<string>
     */
    public function playerNames(): array
    {
        $list = $this->data['players_list'] ?? null;
        if (!is_array($list)) {
            return [];
        }

        $names = [];
        foreach ($list as $player) {
            if (is_string($player)) {
                $names[] = $player;
            } elseif (is_array($player) && isset($player['name']) && is_string($player['name'])) {
                $names[] = $player['name'];
            }
        }

        return $names;
    }

    /**
     * Connected players as structured rows, normalized across protocols --
     * 'name' always, plus 'score' / 'duration_sec' where the protocol reports
     * them (A2S, Quake, GameSpy). Use playerNames() if you only want the names.
     *
     * @return list<array{name: string, score?: int, duration_sec?: float}>
     */
    public function playerList(): array
    {
        $list = $this->data['players_list'] ?? null;
        if (!is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $p) {
            if (is_string($p)) {
                $out[] = ['name' => $p];
                continue;
            }
            if (is_array($p)) {
                $player = ['name' => is_string($p['name'] ?? null) ? $p['name'] : ''];
                if (isset($p['score']) && is_int($p['score'])) {
                    $player['score'] = $p['score'];
                }
                $dur = $p['duration_sec'] ?? $p['duration'] ?? $p['time'] ?? null;
                if (is_int($dur) || is_float($dur)) {
                    $player['duration_sec'] = (float) $dur;
                }
                $out[] = $player;
            }
        }

        return $out;
    }

    /**
     * Flat plain-array form (matches the JSON the CLI emits). Merges the raw
     * protocol data on top of the envelope fields.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->server->id,
            'host' => $this->server->host,
            'port' => $this->server->port,
            'protocol' => $this->server->protocol,
            'online' => $this->online,
            'ping_ms' => $this->pingMs,
            'error' => $this->error,
            'error_code' => $this->errorCode,
            ...$this->data,
        ];
    }

    /**
     * Alias for toArray(), named to match the Node port's Result.toObject() so
     * cross-port code reads identically.
     *
     * @return array<string,mixed>
     */
    public function toObject(): array
    {
        return $this->toArray();
    }
}
