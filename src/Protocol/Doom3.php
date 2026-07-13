<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Buffer\ByteReader;
use GameQuery\Server;

/**
 * id Tech 4 "getInfo" query (UDP) — Doom 3, Quake 4, Enemy Territory: Quake
 * Wars, and Prey.
 *
 * Conversation (single UDP request/response):
 *   -> \xFF\xFF getInfo \x00 <challenge:4>
 *   <- \xFF\xFF infoResponse \x00 <challenge:4> key\0value\0...\0 <players>
 *
 * Uses a two-byte 0xFF out-of-band marker (not four like id Tech 3). Server
 * variables use the si_* naming (si_name, si_map, si_maxPlayers). The player
 * roster that follows the variables is parsed best-effort.
 */
final class Doom3 extends AbstractProtocol
{
    private const OOB = "\xFF\xFF";

    public static function name(): string
    {
        return 'doom3';
    }

    public function transport(): string
    {
        return 'udp';
    }

    public function initialStep(Server $server): array
    {
        return ['tag' => 'info', 'packet' => self::OOB . "getInfo\x00" . "\x00\x00\x00\x00"];
    }

    public function nextStep(Server $server, array $history): ?array
    {
        return null;
    }

    public function parse(Server $server, array $history): array
    {
        $raw = $this->responseFor($history, 'info');
        if ($raw === null) {
            return [];
        }

        $marker = strpos($raw, 'infoResponse');
        if ($marker === false) {
            return [];
        }

        // Skip "infoResponse\0" then the 4-byte challenge echo.
        $reader = new ByteReader(substr($raw, $marker + strlen('infoResponse') + 1));
        $reader->skip(4);

        $cvars = [];
        while (!$reader->eof()) {
            $key = $reader->readCString();
            if ($key === '') {
                break;
            }
            $cvars[$key] = $reader->readCString();
        }

        $players = $this->parsePlayers($reader);
        $numPlayers = isset($cvars['si_numPlayers']) ? (int) $cvars['si_numPlayers'] : count($players);

        $result = [
            'name' => $cvars['si_name'] ?? 'Doom3 Server',
            'map' => $cvars['si_map'] ?? null,
            'max_players' => isset($cvars['si_maxPlayers']) ? (int) $cvars['si_maxPlayers'] : 0,
            'players' => $numPlayers,
            'players_list' => $players,
            'password_protected' => isset($cvars['si_usePass']) ? (bool) (int) $cvars['si_usePass'] : false,
            'rules' => $cvars,
        ];
        if (isset($cvars['gamename'])) {
            $result['game'] = $cvars['gamename'];
        }
        if (isset($cvars['si_version'])) {
            $result['version'] = $cvars['si_version'];
        }

        return $result;
    }

    /** Player rows: <id:1><ping:2><rate:4><name\0>, terminated by id 0x20 (32) or EOF. */
    private function parsePlayers(ByteReader $reader): array
    {
        $names = [];
        while (!$reader->eof()) {
            try {
                $id = $reader->readUInt8();
                if ($id === 0x20 || $reader->remaining() < 3) {
                    break;
                }
                $reader->readUInt16();  // ping
                $reader->readUInt32();  // rate
                $name = $reader->readCString();
            } catch (\Throwable) {
                break;
            }
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }
}
