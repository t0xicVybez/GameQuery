<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Server;

/**
 * FiveM / CFX (GTA V multiplayer) server query.
 *
 * FiveM servers don't speak a binary query protocol -- they expose plain
 * HTTP JSON endpoints on the game port (default 30120), so this speaks
 * HTTP over TCP like the Palworld protocol, but without authentication.
 *
 * Conversation shape:
 *   1. GET /info.json     -> server vars (name, map, gametype, max clients)
 *   2. GET /players.json  -> connected player list                [optional]
 *
 * The current player count comes from the length of /players.json, since
 * /info.json does not carry a live count. Server configuration lives under
 * the "vars" object on modern builds but has historically also appeared at
 * the top level, so both locations are checked.
 *
 * Known limitation: like Palworld, completion detection relies on the
 * Content-Length header and has no chunked-transfer-encoding path.
 */
final class FiveM extends AbstractProtocol
{
    public function __construct(
        private readonly bool $includePlayers = true,
    ) {
    }

    public static function name(): string
    {
        return 'fivem';
    }

    public function transport(): string
    {
        return 'tcp';
    }

    public function initialStep(Server $server): array
    {
        return [
            'tag' => 'info',
            'packet' => $this->buildRequest($server, '/info.json'),
        ];
    }

    public function nextStep(Server $server, array $history): ?array
    {
        if ($this->includePlayers && !$this->hasTag($history, 'players')) {
            return [
                'tag' => 'players',
                'packet' => $this->buildRequest($server, '/players.json'),
            ];
        }

        return null;
    }

    public function isResponseComplete(string $buffer): bool
    {
        $headerEnd = strpos($buffer, "\r\n\r\n");

        if ($headerEnd === false) {
            return false;
        }

        $headers = substr($buffer, 0, $headerEnd);
        $body = substr($buffer, $headerEnd + 4);

        if (preg_match('/^Content-Length:\s*(\d+)/mi', $headers, $matches)) {
            return strlen($body) >= (int) $matches[1];
        }

        return true;
    }

    public function parse(Server $server, array $history): array
    {
        $result = [];

        $infoRaw = $this->responseFor($history, 'info');
        if ($infoRaw !== null) {
            [$status, $body] = $this->splitHttpResponse($infoRaw);

            if ($status === 200) {
                $info = json_decode($body, true);
                if (is_array($info)) {
                    $vars = is_array($info['vars'] ?? null) ? $info['vars'] : [];
                    $pick = static fn (string $key, mixed $default = null) => $vars[$key] ?? $info[$key] ?? $default;

                    $name = $pick('sv_projectName') ?? $pick('sv_hostname') ?? $info['hostname'] ?? 'FiveM Server';
                    $result['name'] = is_string($name) ? $name : 'FiveM Server';
                    $result['map'] = $pick('mapname', $info['mapname'] ?? null);
                    $result['gametype'] = $pick('gametype');
                    $result['max_players'] = (int) ($pick('sv_maxClients') ?? $pick('sv_maxclients') ?? $info['sv_maxclients'] ?? 0);
                    if (isset($info['server'])) {
                        $result['version'] = $info['server'];
                    }
                }
            }
        }

        $playersRaw = $this->responseFor($history, 'players');
        if ($playersRaw !== null) {
            [$status, $body] = $this->splitHttpResponse($playersRaw);

            if ($status === 200) {
                $players = json_decode($body, true);
                if (is_array($players)) {
                    $result['players'] = count($players);
                    $result['players_list'] = array_map(
                        static fn ($p) => is_array($p) ? ($p['name'] ?? 'unknown') : 'unknown',
                        $players
                    );
                }
            }
        }

        return $result;
    }

    private function buildRequest(Server $server, string $path): string
    {
        // No Connection: close -- the /info + /players requests reuse one
        // keep-alive connection, so the socket must stay open between them.
        return "GET {$path} HTTP/1.1\r\n"
            . "Host: {$server->host}:{$server->port}\r\n"
            . "Accept: application/json\r\n"
            . "User-Agent: GameQuery-PHP\r\n"
            . "\r\n";
    }

    /** @return array{0: int, 1: string} [status code, body] */
    private function splitHttpResponse(string $raw): array
    {
        $headerEnd = strpos($raw, "\r\n\r\n");
        $headers = $headerEnd !== false ? substr($raw, 0, $headerEnd) : $raw;
        $body = $headerEnd !== false ? substr($raw, $headerEnd + 4) : '';

        $status = 0;
        if (preg_match('#^HTTP/\d\.\d\s+(\d+)#', $headers, $matches)) {
            $status = (int) $matches[1];
        }

        return [$status, $body];
    }
}
