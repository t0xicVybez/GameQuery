<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Buffer\ByteReader;
use GameQuery\Server;

/**
 * GameSpy protocol version 3 (UDP), used by Battlefield 2, Crysis, Unreal
 * Tournament 3, Quake 4, and many other mid-2000s titles.
 *
 * Conversation shape (challenge/response, two round trips):
 *   1. handshake  ->  \xFE\xFD\x09 <sessionId:4>
 *      reply       <-  \x09 <sessionId:4> <challenge ascii int\0>
 *   2. info       ->  \xFE\xFD\x00 <sessionId:4> <challenge:int32 BE> \xFF\xFF\xFF\x01
 *      reply       <-  \x00 <sessionId:4> splitnum\0.. key\0val\0..\0\0 player_\0\0 name\0..
 *
 * The reply carries a backslash-free, null-delimited key/value block for the
 * server variables (hostname, mapname, maxplayers, numplayers, ...) followed
 * by a player-name block.
 *
 * Known limitation: large servers (e.g. a full 64-slot BF2) split the reply
 * across several UDP packets marked with a `splitnum` index. This reads the
 * first datagram only — enough for the server variables and the start of the
 * player list, but not a guaranteed-complete roster on those servers.
 */
final class GameSpy3 extends AbstractProtocol
{
    private const SESSION_ID = "\x04\x05\x06\x07";

    public static function name(): string
    {
        return 'gamespy3';
    }

    public function transport(): string
    {
        return 'udp';
    }

    public function initialStep(Server $server): array
    {
        return ['tag' => 'challenge', 'packet' => "\xFE\xFD\x09" . self::SESSION_ID];
    }

    public function nextStep(Server $server, array $history): ?array
    {
        if ($this->hasTag($history, 'info')) {
            return null;
        }

        $challengeReply = $this->responseFor($history, 'challenge');
        if ($challengeReply === null) {
            return null;
        }

        // Reply: \x09 <sessionId:4> <ascii challenge int, null-terminated>
        $challengeStr = trim(substr($challengeReply, 5));
        $challengeStr = rtrim($challengeStr, "\x00");
        $challenge = (int) $challengeStr;

        $packet = "\xFE\xFD\x00"
            . self::SESSION_ID
            . pack('N', $challenge & 0xFFFFFFFF) // 4-byte big-endian
            . "\xFF\xFF\xFF\x01";

        return ['tag' => 'info', 'packet' => $packet];
    }

    public function parse(Server $server, array $history): array
    {
        $raw = $this->responseFor($history, 'info');
        if ($raw === null) {
            return [];
        }

        // Strip \x00 <sessionId:4>, then the "splitnum\0" marker + 2 flag bytes.
        $reader = new ByteReader($raw);
        $reader->skip(1);          // type \x00
        $reader->skip(4);          // session id
        $rest = $reader->read($reader->remaining());

        $splitPos = strpos($rest, "splitnum\x00");
        if ($splitPos !== false) {
            // "splitnum\0" (9) + 1 flag byte + skip to the payload after it.
            $rest = substr($rest, $splitPos + 9 + 1);
            // A leading 0x00 index byte sometimes precedes the key block.
            $rest = ltrim($rest, "\x00");
        }

        // Key/value block: key\0value\0...\0\0player_\0\0<names>
        $playersMarker = strpos($rest, "\x00\x01player_\x00");
        if ($playersMarker === false) {
            $playersMarker = strpos($rest, "player_\x00\x00");
        }

        $kvBlock = $playersMarker !== false ? substr($rest, 0, $playersMarker) : $rest;
        $cvars = $this->parseKeyValues($kvBlock);

        $playersList = [];
        if ($playersMarker !== false) {
            $playerBlock = substr($rest, $playersMarker);
            $pStart = strpos($playerBlock, "player_\x00");
            if ($pStart !== false) {
                $names = substr($playerBlock, $pStart + strlen("player_\x00") + 1);
                foreach (explode("\x00", $names) as $name) {
                    if ($name === '' || str_starts_with($name, 'team_t')) {
                        break;
                    }
                    $playersList[] = $name;
                }
            }
        }

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

    /** Parse a run of null-delimited key\0value\0 pairs, stopping at an empty key. */
    private function parseKeyValues(string $block): array
    {
        $parts = explode("\x00", $block);
        $cvars = [];
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $key = $parts[$i];
            if ($key === '') {
                break;
            }
            $cvars[$key] = $parts[$i + 1];
        }

        return $cvars;
    }
}
