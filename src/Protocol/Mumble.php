<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Buffer\ByteReader;
use GameQuery\Server;

/**
 * Mumble voice server ping (UDP), the connectionless status ping every Murmur /
 * Mumble server answers on its voice port (default 64738).
 *
 * Conversation (single UDP request/response):
 *   -> \x00\x00\x00\x00 <ident:8>
 *   <- <0><major><minor><patch> <ident:8> <users:4> <maxUsers:4> <bandwidth:4>
 *
 * All big-endian. The ping carries no server name — Mumble does not expose one
 * over this ping — so only version, user count, and max users are reported.
 */
final class Mumble extends AbstractProtocol
{
    public static function name(): string
    {
        return 'mumble';
    }

    public function transport(): string
    {
        return 'udp';
    }

    public function initialStep(Server $server): array
    {
        // 4-byte request type (0) + 8-byte ident echoed back in the reply.
        return ['tag' => 'ping', 'packet' => "\x00\x00\x00\x00" . pack('J', random_int(0, PHP_INT_MAX))];
    }

    public function nextStep(Server $server, array $history): ?array
    {
        return null;
    }

    public function parse(Server $server, array $history): array
    {
        $raw = $this->responseFor($history, 'ping');
        if ($raw === null || strlen($raw) < 24) {
            return [];
        }

        $reader = new ByteReader($raw);
        $reader->skip(1); // leading zero
        $major = $reader->readUInt8();
        $minor = $reader->readUInt8();
        $patch = $reader->readUInt8();
        $reader->skip(8); // ident echo
        $users = $reader->readUInt32BE();
        $maxUsers = $reader->readUInt32BE();
        $bandwidth = $reader->readUInt32BE();

        return [
            'name' => "Mumble Server ({$server->host})",
            'version' => "{$major}.{$minor}.{$patch}",
            'players' => $users,
            'max_players' => $maxUsers,
            'players_list' => [],
            'bandwidth' => $bandwidth,
        ];
    }
}
