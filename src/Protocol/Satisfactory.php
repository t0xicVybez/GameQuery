<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Buffer\ByteReader;
use GameQuery\Server;

/**
 * Satisfactory dedicated server -- the "Lightweight Query" API (UDP, default
 * port 7777) added in Update 8 / 1.0. One Poll Server State request returns the
 * server name, run state, and build number (Net CL).
 *
 * Player counts are NOT exposed by the lightweight query -- those require the
 * authenticated HTTPS API -- so players()/maxPlayers() come back null here.
 *
 * Message layout (all little-endian):
 *   request  0xF6D5 <type=0> <ver=1> <cookie:8> <terminator=1>
 *   response 0xF6D5 <type=1> <ver=1> <cookie:8> <state:1> <netCL:4> <flags:8>
 *            <numSubStates:1> [subId:2 subVer:2]* <nameLen:2> <name:utf8>
 */
final class Satisfactory extends AbstractProtocol
{
    private const MAGIC = "\xD5\xF6";                     // 0xF6D5, little-endian
    private const PROTOCOL_VERSION = "\x01";
    private const COOKIE = "\x47\x51\x01\x00\x00\x00\x00\x00";
    private const STATES = ['offline', 'idle', 'loading', 'playing'];

    public static function name(): string
    {
        return 'satisfactory';
    }

    public function transport(): string
    {
        return 'udp';
    }

    public function initialStep(Server $server): array
    {
        $packet = self::MAGIC
            . "\x00" . self::PROTOCOL_VERSION  // message type 0 (poll) + protocol version
            . self::COOKIE
            . "\x01";                          // message terminator

        return ['tag' => 'state', 'packet' => $packet];
    }

    public function nextStep(Server $server, array $history): ?array
    {
        return null;
    }

    public function parse(Server $server, array $history): array
    {
        $raw = $this->responseFor($history, 'state');
        if ($raw === null || strlen($raw) < 25) {
            return [];
        }

        $reader = new ByteReader($raw);
        if ($reader->readUInt16() !== 0xF6D5) {
            return ['raw_type' => 'bad-magic'];
        }
        if ($reader->readUInt8() !== 1) {
            return ['raw_type' => 'not-a-state-response'];
        }
        $reader->skip(1); // protocol version
        $reader->skip(8); // cookie (echoed)
        $state = $reader->readUInt8();
        $netCl = $reader->readUInt32();
        $reader->skip(8); // server flags
        $numSubStates = $reader->readUInt8();
        $reader->skip($numSubStates * 4); // each sub-state: id(2) + version(2)
        $nameLen = $reader->readUInt16();
        $name = $reader->read($nameLen);

        return [
            'name' => $name,
            'state' => self::STATES[$state] ?? 'unknown',
            'state_id' => $state,
            'net_cl' => $netCl,
            'version' => (string) $netCl,
        ];
    }
}
