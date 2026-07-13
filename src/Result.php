<?php

declare(strict_types=1);

namespace GameQuery;

/**
 * Outcome of querying a single server. Always returned, even on timeout --
 * check `online` before trusting the rest of the fields.
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
    ) {
    }

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
            ...$this->data,
        ];
    }
}
