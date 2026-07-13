<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Buffer\ByteReader;
use GameQuery\Server;

/**
 * GameSpy protocol version 2 (UDP) — Halo (PC), Battlefield 1942 / Vietnam,
 * Neverwinter Nights, SWAT 4, Star Wars Battlefront, and other early-2000s
 * titles that sit between the text GameSpy 1 and the challenge-based
 * GameSpy 3 protocols.
 *
 * Conversation shape (single UDP request/response):
 *   -> \xFE\xFD\x00 <instanceId:4> \xFF\xFF\x00   (request rules + players, skip teams)
 *   <- \x00 <instanceId:4>
 *        <key\0 value\0 ...\0>                    server rules, ends on an empty key
 *        <numFields:1><field\0 ...> <player rows...>
 *
 * The server-rules block (hostname, mapname, numplayers, maxplayers, ...) is
 * cleanly delimited and always parsed. The player roster is parsed
 * best-effort from the field/row layout that follows.
 *
 * Known limitation: like GameSpy 3, large rosters can span multiple UDP
 * packets; only the first datagram is read.
 */
final class GameSpy2 extends AbstractProtocol
{
    private const INSTANCE_ID = "\x04\x05\x06\x07";

    public static function name(): string
    {
        return 'gamespy2';
    }

    public function transport(): string
    {
        return 'udp';
    }

    public function initialStep(Server $server): array
    {
        // \xFF (rules) \xFF (players) \x00 (skip teams)
        return ['tag' => 'info', 'packet' => "\xFE\xFD\x00" . self::INSTANCE_ID . "\xFF\xFF\x00"];
    }

    public function nextStep(Server $server, array $history): ?array
    {
        return null;
    }

    public function parse(Server $server, array $history): array
    {
        $raw = $this->responseFor($history, 'info');
        if ($raw === null || strlen($raw) < 5) {
            return [];
        }

        $reader = new ByteReader($raw);
        $reader->skip(1); // \x00 type
        $reader->skip(4); // instance id

        // Server rules: key\0 value\0 ... until an empty key.
        $cvars = [];
        while (!$reader->eof()) {
            $key = $reader->readCString();
            if ($key === '') {
                break;
            }
            $cvars[$key] = $reader->readCString();
        }

        $playersList = $this->parsePlayers($reader);

        $numPlayers = isset($cvars['numplayers']) ? (int) $cvars['numplayers'] : count($playersList);

        $result = [
            'name' => $cvars['hostname'] ?? 'GameSpy Server',
            'map' => $cvars['mapname'] ?? null,
            'max_players' => isset($cvars['maxplayers']) ? (int) $cvars['maxplayers'] : 0,
            'players' => $numPlayers,
            'players_list' => $playersList,
            'password_protected' => isset($cvars['password']) ? (bool) (int) $cvars['password'] : false,
            'rules' => $cvars,
        ];
        if (isset($cvars['gametype'])) {
            $result['gametype'] = $cvars['gametype'];
        }
        if (isset($cvars['gamever'])) {
            $result['version'] = $cvars['gamever'];
        }

        return $result;
    }

    /**
     * Player section: 1 byte field count, that many null-terminated field
     * names, then rows of one null-terminated value per field until an empty
     * value. Returns the values of the field named "player" (the roster).
     */
    private function parsePlayers(ByteReader $reader): array
    {
        if ($reader->eof()) {
            return [];
        }

        try {
            $fieldCount = $reader->readUInt8();
            if ($fieldCount === 0 || $fieldCount > 32) {
                return [];
            }

            $fields = [];
            for ($i = 0; $i < $fieldCount; $i++) {
                $fields[] = rtrim($reader->readCString(), '_');
            }

            $nameIndex = array_search('player', $fields, true);
            if ($nameIndex === false) {
                $nameIndex = 0;
            }

            $names = [];
            while (!$reader->eof()) {
                $row = [];
                for ($i = 0; $i < $fieldCount; $i++) {
                    if ($reader->eof()) {
                        break 2;
                    }
                    $row[$i] = $reader->readCString();
                }
                if (($row[$nameIndex] ?? '') === '') {
                    break;
                }
                $names[] = $row[$nameIndex];
            }

            return $names;
        } catch (\Throwable) {
            return [];
        }
    }
}
