<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Buffer\ByteReader;
use GameQuery\Server;

/**
 * Minecraft: Bedrock Edition server query, over RakNet's connectionless
 * "Unconnected Ping / Unconnected Pong" exchange (UDP, default port 19132).
 *
 * Unlike Java Edition (a TCP length-prefixed JSON handshake), Bedrock answers
 * a single UDP datagram: we send an Unconnected Ping (0x01), the server
 * replies with an Unconnected Pong (0x1C) whose payload ends in a
 * semicolon-delimited MOTD string carrying the name, versions, and player
 * counts.
 *
 * Conversation shape: one packet out, one packet back -- no challenge step.
 *
 * MOTD field layout (semicolon-separated):
 *   0 edition (MCPE/MCEE)  1 motd line 1     2 protocol version  3 version name
 *   4 player count         5 max players     6 server GUID       7 motd line 2
 *   8 gamemode             9 gamemode (num)  10 IPv4 port        11 IPv6 port
 */
final class Bedrock extends AbstractProtocol
{
    /** RakNet OFFLINE_MESSAGE_DATA_ID — the fixed 16-byte "magic" every offline packet carries. */
    private const MAGIC = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";

    public static function name(): string
    {
        return 'bedrock';
    }

    public function transport(): string
    {
        return 'udp';
    }

    public function initialStep(Server $server): array
    {
        // 0x01 | int64 BE client timestamp | MAGIC | int64 BE client GUID
        $packet = "\x01"
            . pack('J', (int) (microtime(true) * 1000))
            . self::MAGIC
            . pack('J', random_int(0, PHP_INT_MAX));

        return ['tag' => 'ping', 'packet' => $packet];
    }

    public function nextStep(Server $server, array $history): ?array
    {
        return null; // single request/response
    }

    public function parse(Server $server, array $history): array
    {
        $raw = $this->responseFor($history, 'ping');
        if ($raw === null || strlen($raw) < 35) {
            return [];
        }

        $reader = new ByteReader($raw);
        $reader->skip(1);   // 0x1C packet id
        $reader->skip(8);   // server timestamp
        $reader->skip(8);   // server GUID
        $reader->skip(16);  // MAGIC
        $length = $reader->readUInt16BE();
        $motd = $reader->remaining() >= $length ? $reader->read($length) : $reader->read($reader->remaining());

        $parts = explode(';', $motd);

        $result = [
            'name' => $parts[1] ?? 'Bedrock Server',
            'edition' => $parts[0] ?? null,
            'version' => $parts[3] ?? null,
            'protocol_version' => isset($parts[2]) ? (int) $parts[2] : null,
            'players' => isset($parts[4]) ? (int) $parts[4] : 0,
            'max_players' => isset($parts[5]) ? (int) $parts[5] : 0,
        ];

        if (isset($parts[7]) && $parts[7] !== '') {
            $result['map'] = $parts[7]; // Bedrock reports the world name as the second MOTD line
        }
        if (isset($parts[8]) && $parts[8] !== '') {
            $result['gamemode'] = $parts[8];
        }

        return $result;
    }
}
