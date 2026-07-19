<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Buffer\ByteReader;
use GameQuery\Server;

/**
 * Valve's A2S server query protocol (UDP).
 *
 * This is the protocol behind every Source-engine game (CS2, CS:GO, TF2,
 * Garry's Mod, ...) and, because A2S is a generic feature of the Steamworks
 * Game Server SDK rather than something specific to the Source engine, it
 * also answers for a large number of non-Source Steam titles that register
 * with the Steam server browser (Rust, ARK, Space Engineers, and others).
 * SCUM in particular is known to expose A2S on its query port even though
 * the game itself runs on Unreal Engine.
 *
 * Conversation shape:
 *   1. A2S_INFO            -> server name/map/player count/etc.
 *   2. A2S_PLAYER (chal.)  -> challenge number
 *   3. A2S_PLAYER (real)   -> per-player list                 [optional]
 *   4. A2S_RULES (chal.)   -> challenge number
 *   5. A2S_RULES (real)    -> cvar/rule key-value pairs        [optional]
 *
 * Known limitation: this implementation does not reassemble multi-packet
 * (fragmented) A2S responses or handle bzip2-compressed replies. Both are
 * edge cases for A2S_RULES on servers with a very large cvar list. A2S_INFO
 * and A2S_PLAYER fit in a single UDP datagram for the overwhelming majority
 * of real-world servers.
 */
final class Source extends AbstractProtocol
{
    private const HEADER = "\xFF\xFF\xFF\xFF";
    private const CHALLENGE_PLACEHOLDER = "\xFF\xFF\xFF\xFF";

    public function __construct(
        private readonly bool $includePlayers = true,
        private readonly bool $includeRules = false,
    ) {
    }

    public static function name(): string
    {
        return 'source';
    }

    public function transport(): string
    {
        return 'udp';
    }

    public function supportsMultiPacket(): bool
    {
        return true;
    }

    /**
     * Reassemble a split A2S reply. A single-packet reply (0xFFFFFFFF header) is
     * returned as-is; a split reply (0xFFFFFFFE) is collected by packet number
     * across datagrams and its payloads concatenated once all `Total` arrive.
     * Assumes the modern Source split header (with the 2-byte Size field);
     * bzip2-compressed splits (legacy GoldSource) aren't decompressed.
     *
     * @param list<string> $fragments
     */
    public function reassemble(array $fragments): ?string
    {
        $first = $fragments[0] ?? null;
        if ($first === null || strlen($first) < 4) {
            return null;
        }

        $header = unpack('V', substr($first, 0, 4))[1];
        if ($header !== 0xFFFFFFFE) {
            return $first; // single packet (0xFFFFFFFF) or not A2S -- hand straight to parse()
        }

        $payloads = [];
        $total = 0;
        $compressed = false;
        foreach ($fragments as $frag) {
            if (strlen($frag) < 12 || unpack('V', substr($frag, 0, 4))[1] !== 0xFFFFFFFE) {
                continue;
            }
            if (unpack('V', substr($frag, 4, 4))[1] >= 0x80000000) {
                $compressed = true; // high bit = bzip2
            }
            $total = ord($frag[8]);
            $number = ord($frag[9]);
            $payloads[$number] = substr($frag, 12); // skip header(4)+id(4)+total(1)+number(1)+size(2)
        }

        if ($compressed || $total === 0) {
            return $first; // can't reassemble; parse() degrades gracefully
        }
        if (count($payloads) < $total) {
            return null; // still waiting for datagrams
        }

        $parts = '';
        for ($i = 0; $i < $total; $i++) {
            if (!isset($payloads[$i])) {
                return null;
            }
            $parts .= $payloads[$i];
        }

        return $parts;
    }

    public function initialStep(Server $server): array
    {
        return [
            'tag' => 'info',
            'packet' => self::HEADER . "\x54" . "Source Engine Query\x00",
        ];
    }

    public function nextStep(Server $server, array $history): ?array
    {
        $infoRaw = $this->responseFor($history, 'info');
        if ($infoRaw === null) {
            // Still waiting on the very first reply; nothing new to send.
            return null;
        }

        // A2S_INFO challenge (Valve, Dec 2020): some servers reply to A2S_INFO with a
        // 0x41 challenge that must be echoed back before they send the real payload.
        if ($this->isChallengeReply($infoRaw) && !$this->hasTag($history, 'info_retry')) {
            return [
                'tag' => 'info_retry',
                'packet' => self::HEADER . "\x54" . "Source Engine Query\x00" . $this->extractChallenge($infoRaw),
            ];
        }

        if ($this->includePlayers && !$this->hasTag($history, 'player_challenge')) {
            return [
                'tag' => 'player_challenge',
                'packet' => self::HEADER . "\x55" . self::CHALLENGE_PLACEHOLDER,
            ];
        }

        if ($this->includePlayers && $this->hasTag($history, 'player_challenge') && !$this->hasTag($history, 'player_data')) {
            $challenge = $this->extractChallenge($this->responseFor($history, 'player_challenge'));

            return [
                'tag' => 'player_data',
                'packet' => self::HEADER . "\x55" . $challenge,
            ];
        }

        if ($this->includeRules && !$this->hasTag($history, 'rules_challenge')) {
            return [
                'tag' => 'rules_challenge',
                'packet' => self::HEADER . "\x56" . self::CHALLENGE_PLACEHOLDER,
            ];
        }

        if ($this->includeRules && $this->hasTag($history, 'rules_challenge') && !$this->hasTag($history, 'rules_data')) {
            $challenge = $this->extractChallenge($this->responseFor($history, 'rules_challenge'));

            return [
                'tag' => 'rules_data',
                'packet' => self::HEADER . "\x56" . $challenge,
            ];
        }

        return null;
    }

    public function parse(Server $server, array $history): array
    {
        $result = [];

        // Prefer the challenge-completed reply when the server required one.
        $info = $this->responseFor($history, 'info_retry') ?? $this->responseFor($history, 'info');
        if ($info !== null) {
            $result = array_merge($result, $this->parseInfo($info));
        }

        $playerData = $this->responseFor($history, 'player_data');
        if ($playerData !== null) {
            $result['players_list'] = $this->parsePlayers($playerData);
        }

        $rulesData = $this->responseFor($history, 'rules_data');
        if ($rulesData !== null) {
            $result['rules'] = $this->parseRules($rulesData);
        }

        return $result;
    }

    /** An A2S reply is a challenge when the type byte (after the 0xFFFFFFFF header) is 0x41 ('A'). */
    private function isChallengeReply(?string $raw): bool
    {
        return $raw !== null && strlen($raw) >= 5 && ord($raw[4]) === 0x41;
    }

    /** Pulls the 4-byte challenge number out of an S2C_CHALLENGE ('A') reply. */
    private function extractChallenge(?string $response): string
    {
        if ($response === null || strlen($response) < 9) {
            return self::CHALLENGE_PLACEHOLDER;
        }

        // Bytes: 0xFF 0xFF 0xFF 0xFF 'A' <4-byte challenge>
        return substr($response, 5, 4);
    }

    private function parseInfo(string $raw): array
    {
        $reader = new ByteReader($raw);
        $reader->skip(4); // 0xFFFFFFFF header

        $type = $reader->readUInt8(); // expect 0x49 'I'
        if ($type !== 0x49) {
            return ['online' => true, 'raw_type' => $type];
        }

        $data = [
            'protocol_version' => $reader->readUInt8(),
            'name' => $reader->readCString(),
            'map' => $reader->readCString(),
            'folder' => $reader->readCString(),
            'game' => $reader->readCString(),
            'app_id' => $reader->readUInt16(),
            'players' => $reader->readUInt8(),
            'max_players' => $reader->readUInt8(),
            'bots' => $reader->readUInt8(),
            'server_type' => $this->decodeServerType($reader->readUInt8()),
            'environment' => $this->decodeEnvironment($reader->readUInt8()),
            'password_protected' => (bool) $reader->readUInt8(),
            'vac_secured' => (bool) $reader->readUInt8(),
        ];

        // "The Ship" (app_id 2400) is the one A2S game that inserts three extra
        // bytes here -- game mode, witness count, and round duration -- before
        // the version string. Consume them so the rest of the payload stays aligned.
        if ($data['app_id'] === 2400) {
            $data['ship_mode'] = $reader->readUInt8();
            $data['ship_witnesses'] = $reader->readUInt8();
            $data['ship_duration'] = $reader->readUInt8();
        }

        if (!$reader->eof()) {
            $data['version'] = $reader->readCString();
        }

        if (!$reader->eof()) {
            $edf = $reader->readUInt8();

            if (($edf & 0x80) && $reader->remaining() >= 2) {
                $data['game_port'] = $reader->readUInt16();
            }
            if (($edf & 0x10) && $reader->remaining() >= 8) {
                $data['steam_id'] = $reader->readUInt64();
            }
            if (($edf & 0x40) && $reader->remaining() >= 2) {
                $data['spectator_port'] = $reader->readUInt16();
                $data['spectator_name'] = $reader->readCString();
            }
            if (($edf & 0x20)) {
                $data['keywords'] = $reader->readCString();
            }
            if (($edf & 0x01) && $reader->remaining() >= 8) {
                $data['game_id'] = $reader->readUInt64();
            }
        }

        return $data;
    }

    private function parsePlayers(string $raw): array
    {
        $reader = new ByteReader($raw);
        $reader->skip(4);
        $type = $reader->readUInt8(); // expect 0x44 'D'

        if ($type !== 0x44) {
            return [];
        }

        $count = $reader->readUInt8();
        $players = [];

        for ($i = 0; $i < $count && !$reader->eof(); $i++) {
            $players[] = [
                'index' => $reader->readUInt8(),
                'name' => $reader->readCString(),
                'score' => $reader->readInt32(),
                'duration_sec' => $reader->readFloat(),
            ];
        }

        return $players;
    }

    private function parseRules(string $raw): array
    {
        $reader = new ByteReader($raw);
        $reader->skip(4);
        $type = $reader->readUInt8(); // expect 0x45 'E'

        if ($type !== 0x45) {
            return [];
        }

        $count = $reader->readUInt16();
        $rules = [];

        for ($i = 0; $i < $count && !$reader->eof(); $i++) {
            $key = $reader->readCString();
            $value = $reader->readCString();
            $rules[$key] = $value;
        }

        return $rules;
    }

    private function decodeServerType(int $byte): string
    {
        return match (chr($byte)) {
            'd' => 'dedicated',
            'l' => 'listen',
            'p' => 'proxy',
            default => 'unknown',
        };
    }

    private function decodeEnvironment(int $byte): string
    {
        return match (chr($byte)) {
            'l' => 'linux',
            'w' => 'windows',
            'm', 'o' => 'mac',
            default => 'unknown',
        };
    }
}
