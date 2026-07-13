<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Buffer\ByteReader;
use GameQuery\Server;

/**
 * Unreal Engine 2 query protocol (UDP), used by Unreal Tournament 2003/2004,
 * Killing Floor, Red Orchestra, America's Army 2, Postal 2, and other UE2
 * titles.
 *
 * Conversation shape:
 *   1. details  ->  \x79\x00\x00\x00\x00
 *      reply     <-  serverid, ip, ports, name, map, gametype, player counts
 *   2. players  ->  \x79\x00\x00\x00\x02                              [optional]
 *      reply     <-  repeated (id, name, ping, score, statsid) entries
 *
 * Strings are length-prefixed: one byte giving the byte count (including the
 * trailing null) followed by the bytes. Integers are little-endian.
 *
 * Known limitation: this reads single-byte-length ASCII/UTF-8 strings. Server
 * names stored as UE2 UCS-2 "unicode" strings (high-bit length) are read
 * best-effort and may be garbled; player counts and maps are unaffected.
 */
final class Unreal2 extends AbstractProtocol
{
    public function __construct(
        private readonly bool $includePlayers = true,
    ) {
    }

    public static function name(): string
    {
        return 'unreal2';
    }

    public function transport(): string
    {
        return 'udp';
    }

    public function initialStep(Server $server): array
    {
        return ['tag' => 'details', 'packet' => "\x79\x00\x00\x00\x00"];
    }

    public function nextStep(Server $server, array $history): ?array
    {
        if ($this->includePlayers && !$this->hasTag($history, 'players')) {
            return ['tag' => 'players', 'packet' => "\x79\x00\x00\x00\x02"];
        }

        return null;
    }

    public function parse(Server $server, array $history): array
    {
        $result = [];

        $details = $this->responseFor($history, 'details');
        if ($details !== null) {
            $result = array_merge($result, $this->parseDetails($details));
        }

        $players = $this->responseFor($history, 'players');
        if ($players !== null) {
            $list = $this->parsePlayers($players);
            $result['players_list'] = $list;
            // Prefer the authoritative count from details; fall back to the roster.
            if (!isset($result['players'])) {
                $result['players'] = count($list);
            }
        }

        return $result;
    }

    private function parseDetails(string $raw): array
    {
        $reader = new ByteReader($raw);
        $reader->skip(5); // response header

        try {
            $reader->readInt32();               // server id
            $this->readUStr($reader);           // server ip
            $reader->readInt32();               // game port
            $reader->readInt32();               // query port
            $name = $this->readUStr($reader);
            $map = $this->readUStr($reader);
            $gametype = $this->readUStr($reader);
            $players = $reader->readInt32();
            $maxPlayers = $reader->readInt32();
        } catch (\Throwable) {
            return ['name' => 'Unreal2 Server'];
        }

        return [
            'name' => $name !== '' ? $name : 'Unreal2 Server',
            'map' => $map !== '' ? $map : null,
            'gametype' => $gametype !== '' ? $gametype : null,
            'players' => $players,
            'max_players' => $maxPlayers,
        ];
    }

    private function parsePlayers(string $raw): array
    {
        $reader = new ByteReader($raw);
        $reader->skip(5); // response header

        $names = [];
        while (!$reader->eof()) {
            try {
                $reader->readInt32();            // player id
                $name = $this->readUStr($reader);
                $reader->readInt32();            // ping
                $reader->readInt32();            // score
                $reader->readInt32();            // stats id
            } catch (\Throwable) {
                break;
            }
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** UE2 length-prefixed string: 1-byte byte-count (incl. null), then bytes. */
    private function readUStr(ByteReader $reader): string
    {
        $len = $reader->readUInt8();
        if ($len === 0) {
            return '';
        }
        if ($len > 0x80) {
            // UCS-2 "unicode" string: (len & 0x7F) characters, 2 bytes each.
            $chars = $len & 0x7F;
            $bytes = $reader->read(min($chars * 2, $reader->remaining()));
            return rtrim(@mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE') ?: '', "\x00");
        }

        return rtrim($reader->read(min($len, $reader->remaining())), "\x00");
    }
}
