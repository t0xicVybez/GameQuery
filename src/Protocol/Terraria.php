<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Server;

/**
 * Terraria dedicated servers running the TShock server mod, which exposes a
 * REST API (vanilla Terraria has no query protocol at all). This is plain HTTP
 * over TCP on the REST port (default 7878, distinct from the 7777 game port).
 *
 * TShock's `/v2/server/status` is a *public* endpoint — it only requires
 * `RestApiEnabled = true` server-side, not a token — so the token is optional:
 *
 *   'token' => a TShock REST token, appended only when provided (needed only
 *              if an admin has locked the status endpoint down).
 *
 *   $gq->addServer('terraria', '203.0.113.10:7878');                       // anonymous
 *   $gq->addServer('terraria', '203.0.113.10:7878', options: ['token' => $t]); // with token
 *
 * Conversation: GET /v2/server/status?players=true[&token=<token>]. The single
 * JSON reply carries the world name, player count, max players, and the
 * connected player list.
 */
final class Terraria extends AbstractProtocol
{
    public static function name(): string
    {
        return 'terraria';
    }

    public function transport(): string
    {
        return 'tcp';
    }

    public function initialStep(Server $server): array
    {
        $token = $server->options['token'] ?? null;
        $path = '/v2/server/status?players=true';
        if (is_string($token) && $token !== '') {
            $path .= '&token=' . rawurlencode($token);
        }

        return ['tag' => 'status', 'packet' => $this->buildRequest($server, $path)];
    }

    public function nextStep(Server $server, array $history): ?array
    {
        return null;
    }

    public function isResponseComplete(string $buffer): bool
    {
        return Http::isComplete($buffer);
    }

    public function parse(Server $server, array $history): array
    {
        $raw = $this->responseFor($history, 'status');
        if ($raw === null) {
            return [];
        }

        [$httpStatus, $body] = Http::split($raw);
        if ($httpStatus !== 200) {
            return [];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['parse_error' => true];
        }

        // TShock reports its own "status" string ("200" on success).
        if (($data['status'] ?? '200') !== '200' && (int) ($data['status'] ?? 200) !== 200) {
            return [];
        }

        $players = [];
        foreach ((array) ($data['players'] ?? []) as $player) {
            if (is_array($player) && isset($player['nickname'])) {
                $players[] = (string) $player['nickname'];
            } elseif (is_string($player)) {
                $players[] = $player;
            }
        }

        return [
            'name' => (string) ($data['name'] ?? 'Terraria Server'),
            'map' => $data['world'] ?? null,
            'players' => (int) ($data['playercount'] ?? count($players)),
            'max_players' => (int) ($data['maxplayers'] ?? 0),
            'version' => $data['serverversion'] ?? null,
            'players_list' => $players,
        ];
    }

    private function buildRequest(Server $server, string $path): string
    {
        return "GET {$path} HTTP/1.1\r\n"
            . "Host: {$server->host}:{$server->port}\r\n"
            . "Accept: application/json\r\n"
            . "User-Agent: GameQuery-PHP\r\n"
            . "Connection: close\r\n"
            . "\r\n";
    }
}
