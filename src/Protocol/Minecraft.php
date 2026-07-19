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
 * status packet. Optionally (via $includePing / the `minecraft-ping` key) it
 * then runs the SLP Ping/Pong (0x01) and reports that round trip as
 * data.ping_ms -- a purer network latency than connect+status time.
 *
 * Bedrock Edition uses a completely different UDP-based RakNet protocol
 * (see the `bedrock` protocol) and is intentionally out of scope here.
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

    /**
     * @param bool $includePing When true, follow the status exchange with the
     *   SLP ping/pong (0x01) and report its round trip as data.ping_ms. Costs
     *   an extra round trip; pre-1.7 / non-responding servers make it time out
     *   (the status data still comes back). Off by default.
     */
    public function __construct(private readonly bool $includePing = false)
    {
    }

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
        if (!$this->includePing) {
            return null; // single round trip: status reply is enough
        }
        if (!$this->hasTag($history, 'status') || $this->hasTag($history, 'ping')) {
            return null;
        }

        // SLP ping (0x01) carrying our send-time as the 8-byte payload; the
        // server echoes it in the pong, so parse() can recover the round-trip
        // time without any per-step timing from the transport.
        $payload = pack('J', (int) round(microtime(true) * 1000)); // big-endian uint64 ms
        $ping = (new ByteWriter())
            ->writeVarInt(0x01)
            ->writeRaw($payload)
            ->withVarIntLengthPrefix();

        return ['tag' => 'ping', 'packet' => $ping];
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

        $result = [
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

        // Recover the SLP ping/pong round trip from the echoed send-time, if we ran it.
        $pong = $this->responseFor($history, 'ping');
        if ($pong !== null) {
            try {
                $reader = new ByteReader($pong);
                $reader->readVarInt(); // packet length
                if ($reader->readVarInt() === 0x01 && $reader->remaining() >= 8) {
                    $sent = unpack('J', $reader->read(8))[1];
                    $result['ping_ms'] = max(0, (int) round(microtime(true) * 1000) - $sent);
                }
            } catch (\Throwable) {
                // no usable pong; leave ping_ms unset
            }
        }

        return $result;
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
