<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Exception\GameQueryException;
use GameQuery\Server;

/**
 * Palworld's dedicated server exposes a REST API (RESTAPIEnabled=True in
 * PalWorldSettings.ini), not a binary query protocol -- unlike Source/A2S,
 * this is plain HTTP with Basic Auth over TCP, listening by default on
 * port 8212 (game traffic itself is 8211/UDP and isn't queried here).
 *
 * Required per-server option (see GameQuery::addServer's $options param):
 *   'password' => the server's AdminPassword
 * Optional:
 *   'username' => defaults to 'admin', which is what Palworld expects
 *
 *   $gq->addServer('palworld', '203.0.113.10:8212', id: 'my-pal-server', options: [
 *       'password' => 'the-admin-password',
 *   ]);
 *
 * Conversation shape:
 *   1. GET /v1/api/info     -> server name, version, description
 *   2. GET /v1/api/players  -> connected player list                [optional]
 *
 * This deliberately only implements read-only GET endpoints. Palworld's
 * REST API also exposes mutating admin actions (kick/ban/shutdown/broadcast)
 * via POST -- those are out of scope for a status-query library and are
 * not wired up here.
 *
 * Known limitation: response completion detection relies on the
 * Content-Length header. If a future Palworld server version replies with
 * chunked transfer-encoding instead, isResponseComplete() would need a
 * chunked-decoding path added; it doesn't have one today.
 */
final class Palworld extends AbstractProtocol
{
    public function __construct(
        private readonly bool $includePlayers = true,
    ) {
    }

    public static function name(): string
    {
        return 'palworld';
    }

    public function transport(): string
    {
        return 'tcp';
    }

    public function initialStep(Server $server): array
    {
        return [
            'tag' => 'info',
            'packet' => $this->buildRequest($server, '/v1/api/info'),
        ];
    }

    public function nextStep(Server $server, array $history): ?array
    {
        if ($this->includePlayers && !$this->hasTag($history, 'players')) {
            return [
                'tag' => 'players',
                'packet' => $this->buildRequest($server, '/v1/api/players'),
            ];
        }

        return null;
    }

    public function isResponseComplete(string $buffer): bool
    {
        return Http::isComplete($buffer);
    }

    public function parse(Server $server, array $history): array
    {
        $result = [];

        $infoRaw = $this->responseFor($history, 'info');
        if ($infoRaw !== null) {
            $result = array_merge($result, $this->parseHttpJson($infoRaw, [
                'servername' => 'name',
                'version' => 'version',
                'description' => 'description',
                'worldguid' => 'world_guid',
            ]));
        }

        $playersRaw = $this->responseFor($history, 'players');
        if ($playersRaw !== null) {
            [$status, $body] = Http::split($playersRaw);

            if ($status === 200) {
                $decoded = json_decode($body, true);
                $players = is_array($decoded['players'] ?? null) ? $decoded['players'] : [];

                $result['players'] = count($players);
                $result['players_list'] = array_map(
                    static fn (array $p) => $p['name'] ?? 'unknown',
                    $players
                );
            }
        }

        return $result;
    }

    private function buildRequest(Server $server, string $path): string
    {
        $password = $server->options['password'] ?? null;

        if ($password === null) {
            throw new GameQueryException(
                "Palworld server {$server->label()}: missing required options['password']"
            );
        }

        $username = $server->options['username'] ?? 'admin';
        $auth = base64_encode("{$username}:{$password}");

        return "GET {$path} HTTP/1.1\r\n"
            . "Host: {$server->host}:{$server->port}\r\n"
            . "Authorization: Basic {$auth}\r\n"
            . "Accept: application/json\r\n"
            . "User-Agent: GameQuery-PHP\r\n"
            . "\r\n";
    }

    private function parseHttpJson(string $raw, array $fieldMap): array
    {
        [$status, $body] = Http::split($raw);

        if ($status === 401) {
            return ['auth_error' => true];
        }

        if ($status !== 200) {
            return ['http_error' => $status];
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return ['parse_error' => true];
        }

        $result = [];
        foreach ($fieldMap as $sourceKey => $targetKey) {
            if (array_key_exists($sourceKey, $decoded)) {
                $result[$targetKey] = $decoded[$sourceKey];
            }
        }

        return $result;
    }
}
