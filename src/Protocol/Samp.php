<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Buffer\ByteReader;
use GameQuery\Server;

/**
 * SA-MP (San Andreas Multiplayer) and open.mp, the GTA: San Andreas multiplayer
 * mods. Their query is a connectionless UDP protocol on the game port (default
 * 7777). Every request echoes the server's own address back for anti-spoofing:
 *
 *   "SAMP" <ip:4 octets> <port:2 LE> <opcode>
 *
 * so the protocol needs the host resolved to a numeric IP first (see
 * requiresAddressResolution). Opcode 'i' returns core info; 'c' returns the
 * client list (name + score). Large servers deliberately omit the client list,
 * so players_list may be empty even when the server is full.
 */
final class Samp extends AbstractProtocol
{
    public function __construct(
        private readonly bool $includePlayers = true,
    ) {
    }

    public static function name(): string
    {
        return 'samp';
    }

    public function transport(): string
    {
        return 'udp';
    }

    public function requiresAddressResolution(): bool
    {
        return true;
    }

    public function initialStep(Server $server): array
    {
        return ['tag' => 'info', 'packet' => $this->buildPacket($server, 'i')];
    }

    public function nextStep(Server $server, array $history): ?array
    {
        if ($this->includePlayers && !$this->hasTag($history, 'players')) {
            return ['tag' => 'players', 'packet' => $this->buildPacket($server, 'c')];
        }

        return null;
    }

    public function parse(Server $server, array $history): array
    {
        $result = [];

        $info = $this->responseFor($history, 'info');
        if ($info !== null && strlen($info) > 11) {
            $reader = new ByteReader(substr($info, 11)); // skip the echoed 11-byte header
            $password = $reader->readUInt8();
            $players = $reader->readUInt16();
            $maxPlayers = $reader->readUInt16();
            $name = $reader->read($reader->readUInt32());
            $gamemode = $reader->read($reader->readUInt32());
            $language = $reader->read($reader->readUInt32());

            $result = [
                'name' => $name,
                'gametype' => $gamemode,
                'language' => $language,
                'players' => $players,
                'max_players' => $maxPlayers,
                'password' => $password === 1,
                'players_list' => [],
            ];
        }

        $playersRaw = $this->responseFor($history, 'players');
        if ($playersRaw !== null && strlen($playersRaw) > 11) {
            $reader = new ByteReader(substr($playersRaw, 11));
            $count = $reader->readUInt16();
            $list = [];
            for ($i = 0; $i < $count; $i++) {
                $list[] = $reader->read($reader->readUInt8()); // 1-byte length + name
                $reader->skip(4); // score (int32)
            }
            $result['players_list'] = $list;
        }

        return $result;
    }

    private function buildPacket(Server $server, string $opcode): string
    {
        $octets = array_map('intval', explode('.', $server->address()));
        if (count($octets) !== 4) {
            $octets = [0, 0, 0, 0];
        }

        return 'SAMP'
            . chr($octets[0] & 0xFF) . chr($octets[1] & 0xFF) . chr($octets[2] & 0xFF) . chr($octets[3] & 0xFF)
            . pack('v', $server->port)
            . $opcode;
    }
}
