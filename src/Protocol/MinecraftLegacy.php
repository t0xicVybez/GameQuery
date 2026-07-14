<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Server;

/**
 * Legacy Minecraft: Java Edition Server List Ping, for servers running 1.6 and
 * older (1.7 switched to the modern VarInt/JSON protocol handled by Minecraft).
 * Still useful for old modpack and nostalgia servers.
 *
 * Conversation (single request, TCP, default port 25565):
 *   -> \xFE\x01
 *   <- \xFF <len:uint16 BE, in UTF-16 code units> <UTF-16BE payload>
 *
 * Two payload shapes are handled:
 *   1.4-1.6:  "§1\0<protocol>\0<version>\0<motd>\0<players>\0<max>"
 *   beta-1.3: "<motd>§<players>§<max>"   (no leading §1, § as separator)
 */
final class MinecraftLegacy extends AbstractProtocol
{
    public static function name(): string
    {
        return 'minecraft-legacy';
    }

    public function transport(): string
    {
        return 'tcp';
    }

    public function initialStep(Server $server): array
    {
        return ['tag' => 'ping', 'packet' => "\xFE\x01"];
    }

    public function nextStep(Server $server, array $history): ?array
    {
        return null;
    }

    public function isResponseComplete(string $buffer): bool
    {
        if (strlen($buffer) < 3) {
            return false; // not even the 0xFF marker + length yet
        }
        if ($buffer[0] !== "\xFF") {
            return true; // not a legacy kick packet; hand off and let parse() reject it
        }

        $units = (ord($buffer[1]) << 8) | ord($buffer[2]);

        return strlen($buffer) >= 3 + $units * 2;
    }

    public function parse(Server $server, array $history): array
    {
        $raw = $this->responseFor($history, 'ping');
        if ($raw === null || strlen($raw) < 3 || $raw[0] !== "\xFF") {
            return [];
        }

        $units = (ord($raw[1]) << 8) | ord($raw[2]);
        $payload = $this->decodeUtf16Be(substr($raw, 3, $units * 2));

        if (str_starts_with($payload, "\xC2\xA7" . '1')) {
            // 1.4-1.6: §1 \0 protocol \0 version \0 motd \0 players \0 max
            $parts = explode("\x00", $payload);
            return [
                'name' => $parts[3] ?? 'Minecraft Server',
                'version' => $parts[2] ?? null,
                'protocol_version' => isset($parts[1]) ? (int) $parts[1] : null,
                'players' => (int) ($parts[4] ?? 0),
                'max_players' => (int) ($parts[5] ?? 0),
                'players_list' => [],
            ];
        }

        // beta-1.3: motd § players § max
        $parts = explode("\xC2\xA7", $payload);
        return [
            'name' => $parts[0] ?? 'Minecraft Server',
            'players' => (int) ($parts[1] ?? 0),
            'max_players' => (int) ($parts[2] ?? 0),
            'players_list' => [],
        ];
    }

    /** Decode a UTF-16BE byte string to UTF-8 (BMP range; no external deps). */
    private function decodeUtf16Be(string $bytes): string
    {
        $out = '';
        $len = strlen($bytes) - (strlen($bytes) % 2);
        for ($i = 0; $i < $len; $i += 2) {
            $cp = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]);
            if ($cp < 0x80) {
                $out .= chr($cp);
            } elseif ($cp < 0x800) {
                $out .= chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
            } else {
                $out .= chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
            }
        }

        return $out;
    }
}
