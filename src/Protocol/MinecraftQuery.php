<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Buffer\ByteReader;
use GameQuery\Server;

/**
 * Minecraft's Query protocol (UDP) -- the GameSpy4/UT3-style query a Java
 * server exposes when `enable-query=true` is set (default query port = the
 * game port). Unlike the Server List Ping (`minecraft`), the full-stat query
 * returns the complete player list rather than SLP's truncated sample.
 *
 * Conversation (challenge/response, two round trips):
 *   1. handshake  ->  \xFE\xFD\x09 <sessionId:4>
 *      reply       <-  \x09 <sessionId:4> <challenge ascii int\0>
 *   2. full stat  ->  \xFE\xFD\x00 <sessionId:4> <challenge:int32 BE> \x00\x00\x00\x00
 *      reply       <-  \x00 <sessionId:4> splitnum\x00\x80\x00 <key\0val\0..\0\0>
 *                      \x01player_\x00\x00 <name\0..\0>
 *
 * Known limitation: a very large roster split across `splitnum`-indexed packets
 * isn't fully reassembled -- this reads the first datagram only.
 */
final class MinecraftQuery extends AbstractProtocol
{
    private const SESSION_ID = "\x00\x00\x00\x01";

    public static function name(): string
    {
        return 'minecraft-query';
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
        $challengeStr = rtrim(trim(substr($challengeReply, 5)), "\x00");
        $challenge = (int) $challengeStr;

        $packet = "\xFE\xFD\x00"
            . self::SESSION_ID
            . pack('N', $challenge & 0xFFFFFFFF) // 4-byte big-endian
            . "\x00\x00\x00\x00";                // full-stat request padding

        return ['tag' => 'info', 'packet' => $packet];
    }

    public function parse(Server $server, array $history): array
    {
        $raw = $this->responseFor($history, 'info');
        if ($raw === null) {
            return [];
        }

        $reader = new ByteReader($raw);
        $reader->skip(1);          // type \x00
        $reader->skip(4);          // session id
        $rest = $reader->read($reader->remaining());

        $splitPos = strpos($rest, "splitnum\x00");
        if ($splitPos !== false) {
            $rest = substr($rest, $splitPos + 9 + 1);
            $rest = ltrim($rest, "\x00");
        }

        $playersMarker = strpos($rest, "\x01player_\x00");
        $kvBlock = $playersMarker !== false ? substr($rest, 0, $playersMarker) : $rest;
        $cvars = $this->parseKeyValues($kvBlock);

        $playersList = [];
        if ($playersMarker !== false) {
            $names = substr($rest, $playersMarker + strlen("\x01player_\x00\x00"));
            foreach (explode("\x00", $names) as $name) {
                if ($name === '') {
                    break;
                }
                $playersList[] = $name;
            }
        }

        $numPlayers = isset($cvars['numplayers']) ? (int) $cvars['numplayers'] : count($playersList);

        $result = [
            'name' => $cvars['hostname'] ?? 'Minecraft Server',
            'map' => $cvars['map'] ?? null,
            'players' => $numPlayers,
            'max_players' => isset($cvars['maxplayers']) ? (int) $cvars['maxplayers'] : 0,
            'players_list' => $playersList,
            'password_protected' => false,
            'rules' => $cvars,
        ];
        if (isset($cvars['version'])) {
            $result['version'] = $cvars['version'];
        }
        if (isset($cvars['gametype'])) {
            $result['gametype'] = $cvars['gametype'];
        }
        if (isset($cvars['plugins'])) {
            $result['plugins'] = $cvars['plugins'];
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
