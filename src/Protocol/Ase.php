<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Buffer\ByteReader;
use GameQuery\Server;

/**
 * All-Seeing Eye (ASE) query protocol (UDP) — Multi Theft Auto (MTA:SA) and a
 * handful of other titles that adopted the ASE server browser format.
 *
 * Conversation (single UDP request/response):
 *   -> s
 *   <- EYE1 <gamename><port><hostname><gametype><map><version><password>
 *           <numplayers><maxplayers> <rule pairs...> <player rows...>
 *
 * Every field is a length-prefixed string: one byte giving the length
 * including itself, then (length - 1) bytes. Rules are key/value pairs
 * terminated by an empty key; players carry a per-row flags byte selecting
 * which of name/team/skin/score/ping follow.
 */
final class Ase extends AbstractProtocol
{
    public static function name(): string
    {
        return 'ase';
    }

    public function transport(): string
    {
        return 'udp';
    }

    public function initialStep(Server $server): array
    {
        return ['tag' => 'info', 'packet' => 's'];
    }

    public function nextStep(Server $server, array $history): ?array
    {
        return null;
    }

    public function parse(Server $server, array $history): array
    {
        $raw = $this->responseFor($history, 'info');
        if ($raw === null || strlen($raw) < 4) {
            return [];
        }

        $reader = new ByteReader($raw);
        if ($reader->read(4) !== 'EYE1') {
            return [];
        }

        $gamename = $this->readString($reader);
        $this->readString($reader);            // game port
        $hostname = $this->readString($reader);
        $gametype = $this->readString($reader);
        $map = $this->readString($reader);
        $version = $this->readString($reader);
        $password = $this->readString($reader);
        $numplayers = $this->readString($reader);
        $maxplayers = $this->readString($reader);

        $rules = [];
        while (!$reader->eof()) {
            $key = $this->readString($reader);
            if ($key === '') {
                break;
            }
            $rules[$key] = $this->readString($reader);
        }

        $players = $this->parsePlayers($reader, (int) $numplayers);

        $result = [
            'name' => $hostname !== '' ? $hostname : 'ASE Server',
            'map' => $map !== '' ? $map : null,
            'max_players' => (int) $maxplayers,
            'players' => (int) $numplayers,
            'players_list' => $players,
            'password_protected' => (bool) (int) $password,
            'rules' => $rules,
        ];
        if ($gametype !== '') {
            $result['gametype'] = $gametype;
        }
        if ($version !== '') {
            $result['version'] = $version;
        }
        if ($gamename !== '') {
            $result['game'] = $gamename;
        }

        return $result;
    }

    private function parsePlayers(ByteReader $reader, int $count): array
    {
        $names = [];
        for ($i = 0; $i < $count && !$reader->eof(); $i++) {
            try {
                $flags = $reader->readUInt8();
                $name = ($flags & 0x01) ? $this->readString($reader) : '';
                if ($flags & 0x02) {
                    $this->readString($reader);
                } // team
                if ($flags & 0x04) {
                    $this->readString($reader);
                } // skin
                if ($flags & 0x08) {
                    $this->readString($reader);
                } // score
                if ($flags & 0x10) {
                    $this->readString($reader);
                } // ping
            } catch (\Throwable) {
                break;
            }
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /** ASE length-prefixed string: 1-byte length (incl. itself), then length-1 bytes. */
    private function readString(ByteReader $reader): string
    {
        if ($reader->eof()) {
            return '';
        }
        $len = $reader->readUInt8();
        if ($len <= 1) {
            return '';
        }
        return $reader->read(min($len - 1, $reader->remaining()));
    }
}
