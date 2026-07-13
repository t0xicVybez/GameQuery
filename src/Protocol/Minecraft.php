<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Buffer\ByteReader;
use GameQuery\Buffer\ByteWriter;
use GameQuery\Server;

/**
 * Minecraft: Java Edition "Server List Ping" over TCP.
 *
 * Handshake (state=status) + Status Request are sent back to back on the
 * same connection; the server replies with a single length-prefixed JSON
 * status packet. This does not implement the follow-up Ping/Pong packet
 * pair (0x01), so latency is measured as connect+status round-trip time
 * rather than the protocol's dedicated ping -- close enough for "is it up
 * and how full is it" purposes, cheaper than a second round trip.
 *
 * Bedrock Edition uses a completely different UDP-based RakNet protocol
 * and is intentionally out of scope here.
 */
final class Minecraft extends AbstractProtocol
{
    /**
     * Protocol version number sent in the handshake. Status-ping responses
     * generally ignore this and report the server's real version regardless,
     * so any reasonably recent value works; it does not need to be kept in
     * sync with the target server.
     */
    private const PROTOCOL_VERSION = 767;

    public static function name(): string
    {
        return 'minecraft';
    }

    public function transport(): string
    {
        return 'tcp';
    }

    public function initialStep(Server $server): array
    {
        $handshake = (new ByteWriter())
            ->writeVarInt(0x00)
            ->writeVarInt(self::PROTOCOL_VERSION)
            ->writeMcString($server->host)
            ->writeUInt8(($server->port >> 8) & 0xFF)
            ->writeUInt8($server->port & 0xFF)
            ->writeVarInt(1) // next state: 1 = status
            ->withVarIntLengthPrefix();

        $statusRequest = (new ByteWriter())
            ->writeVarInt(0x00)
            ->withVarIntLengthPrefix();

        return [
            'tag' => 'status',
            'packet' => $handshake . $statusRequest,
        ];
    }

    public function nextStep(Server $server, array $history): ?array
    {
        // Single round trip: once we have a status reply, we're done.
        return null;
    }

    public function isResponseComplete(string $buffer): bool
    {
        try {
            $reader = new ByteReader($buffer);
            $packetLength = $reader->readVarInt();
        } catch (\Throwable) {
            // Not enough bytes yet to even read the length varint.
            return false;
        }

        return $reader->remaining() >= $packetLength;
    }

    public function parse(Server $server, array $history): array
    {
        $raw = $this->responseFor($history, 'status');

        if ($raw === null) {
            return [];
        }

        try {
            $reader = new ByteReader($raw);
            $reader->readVarInt(); // total packet length, already validated by isResponseComplete
            $packetId = $reader->readVarInt(); // expect 0x00
            $jsonLength = $reader->readVarInt();
            $json = $reader->read($jsonLength);
        } catch (\Throwable) {
            return ['parse_error' => true];
        }

        if ($packetId !== 0x00) {
            return ['parse_error' => true];
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return ['parse_error' => true];
        }

        $description = $decoded['description'] ?? '';
        if (is_array($description)) {
            $description = $description['text'] ?? $this->flattenExtra($description);
        }

        return [
            'name' => (string) $description,
            'version' => $decoded['version']['name'] ?? 'unknown',
            'protocol' => $decoded['version']['protocol'] ?? null,
            'players' => $decoded['players']['online'] ?? 0,
            'max_players' => $decoded['players']['max'] ?? 0,
            'players_list' => array_map(
                static fn (array $p) => $p['name'] ?? 'unknown',
                $decoded['players']['sample'] ?? []
            ),
            'has_favicon' => isset($decoded['favicon']),
        ];
    }

    private function flattenExtra(array $chat): string
    {
        $text = $chat['text'] ?? '';

        foreach ($chat['extra'] ?? [] as $part) {
            $text .= is_array($part) ? ($part['text'] ?? '') : (string) $part;
        }

        return $text;
    }
}
