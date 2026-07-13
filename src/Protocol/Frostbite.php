<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Buffer\ByteReader;
use GameQuery\Server;

/**
 * Frostbite / RCON word protocol (TCP) used by DICE's Battlefield line —
 * Battlefield 3, Battlefield 4, Battlefield: Bad Company 2, and the Medal of
 * Honor reboots — over their admin/query port.
 *
 * The wire format is a stream of "words". Each packet is:
 *   <sequence:4> <totalSize:4> <numWords:4>  then numWords x [<len:4> <bytes> \x00]
 * all little-endian. A client request carries sequence 0 (bit 31 = "from
 * server", bit 30 = "is response" are both clear). We issue the unauthenticated
 * `serverInfo` command, whose reply is ["OK", name, players, maxPlayers, mode,
 * map, ...].
 */
final class Frostbite extends AbstractProtocol
{
    public static function name(): string
    {
        return 'frostbite';
    }

    public function transport(): string
    {
        return 'tcp';
    }

    public function initialStep(Server $server): array
    {
        return ['tag' => 'serverInfo', 'packet' => $this->encode(['serverInfo'])];
    }

    public function nextStep(Server $server, array $history): ?array
    {
        return null;
    }

    public function isResponseComplete(string $buffer): bool
    {
        if (strlen($buffer) < 12) {
            return false;
        }

        $size = unpack('V', substr($buffer, 4, 4))[1];

        return strlen($buffer) >= $size;
    }

    public function parse(Server $server, array $history): array
    {
        $raw = $this->responseFor($history, 'serverInfo');
        if ($raw === null || strlen($raw) < 12) {
            return [];
        }

        $reader = new ByteReader($raw);
        $reader->skip(8); // sequence + total size
        $numWords = $reader->readUInt32();

        $words = [];
        for ($i = 0; $i < $numWords; $i++) {
            $len = $reader->readUInt32();
            $words[] = $reader->read($len);
            $reader->skip(1); // null terminator
        }

        if (($words[0] ?? '') !== 'OK') {
            return [];
        }

        return [
            'name' => $words[1] ?? '',
            'players' => (int) ($words[2] ?? 0),
            'max_players' => (int) ($words[3] ?? 0),
            'game' => $words[4] ?? null, // game mode (e.g. ConquestLarge0)
            'map' => $words[5] ?? null,
            'players_list' => [],
            'raw_words' => $words,
        ];
    }

    /** Encode a command word list into a client-originated Frostbite packet. */
    private function encode(array $words): string
    {
        $body = '';
        foreach ($words as $word) {
            $body .= pack('V', strlen($word)) . $word . "\x00";
        }

        $size = 12 + strlen($body);

        return pack('V', 0) . pack('V', $size) . pack('V', count($words)) . $body;
    }
}
