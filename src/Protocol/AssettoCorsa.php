<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Server;

/**
 * Assetto Corsa dedicated server. The server exposes a small HTTP JSON status
 * interface (the "HTTP port", distinct from the UDP game port — pass the HTTP
 * port here). `GET /INFO` returns a single JSON object with the server name,
 * client count, max clients, and current track. /INFO carries no per-driver
 * names, so players_list is empty.
 */
final class AssettoCorsa extends AbstractProtocol
{
    public static function name(): string
    {
        return 'assettocorsa';
    }

    public function transport(): string
    {
        return 'tcp';
    }

    public function initialStep(Server $server): array
    {
        return ['tag' => 'info', 'packet' => $this->buildRequest($server, '/INFO')];
    }

    public function nextStep(Server $server, array $history): ?array
    {
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
        $raw = $this->responseFor($history, 'info');
        if ($raw === null) {
            return [];
        }

        [$status, $body] = $this->splitHttpResponse($raw);
        if ($status !== 200) {
            return [];
        }

        $info = json_decode($body, true);
        if (!is_array($info)) {
            return ['parse_error' => true];
        }

        return [
            'name' => (string) ($info['name'] ?? 'Assetto Corsa Server'),
            'map' => $info['track'] ?? null,
            'players' => (int) ($info['clients'] ?? 0),
            'max_players' => (int) ($info['maxclients'] ?? 0),
            'players_list' => [],
            'password' => (bool) ($info['pass'] ?? false),
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
