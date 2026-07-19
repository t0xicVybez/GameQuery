<?php

declare(strict_types=1);

namespace GameQuery;

/**
 * Steam master-server query (hl2master) -- a "discover" capability that returns
 * a LIST of Source/A2S servers matching a filter, rather than querying one
 * server. Feed the results back into GameQuery::addServer('source', ...) to
 * poll each.
 *
 * The master returns servers in batches of ~230; you page by re-querying with
 * the last IP:port as the seed until a 0.0.0.0:0 terminator arrives.
 *
 * Filter uses Valve's backslash syntax, e.g. '\appid\730' (CS2) or
 * '\gamedir\tf' (TF2). Region 0xFF = all regions.
 */
final class SteamMaster
{
    public const REGION_ALL = 0xFF;

    private const HEADER = "\xFF\xFF\xFF\xFF\x66\x0A";

    /**
     * Pure: parse one A2M_GET_SERVERS_BATCH2 reply into servers + an end flag.
     *
     * @return array{servers: list<string>, done: bool, last: ?string}
     */
    public static function parseBatch(string $data): array
    {
        if (strlen($data) < 6 || substr($data, 0, 6) !== self::HEADER) {
            return ['servers' => [], 'done' => true, 'last' => null];
        }

        $servers = [];
        $done = false;
        $last = null;
        $len = strlen($data);
        for ($o = 6; $o + 6 <= $len; $o += 6) {
            $ip = ord($data[$o]) . '.' . ord($data[$o + 1]) . '.' . ord($data[$o + 2]) . '.' . ord($data[$o + 3]);
            $port = (ord($data[$o + 4]) << 8) | ord($data[$o + 5]);
            if ($ip === '0.0.0.0' && $port === 0) {
                $done = true; // the all-zero entry marks the end of the list
                break;
            }
            $entry = "{$ip}:{$port}";
            $servers[] = $entry;
            $last = $entry;
        }

        return ['servers' => $servers, 'done' => $done, 'last' => $last];
    }

    /**
     * Query the master server and return the de-duplicated list of "ip:port".
     *
     * @param array{master?: string, masterPort?: int, timeoutMs?: int, maxServers?: int, maxPages?: int} $options
     * @return list<string>
     */
    public static function listServers(string $filter = '', int $region = self::REGION_ALL, array $options = []): array
    {
        $host = $options['master'] ?? 'hl2master.steampowered.com';
        $port = $options['masterPort'] ?? 27011;
        $timeoutMs = $options['timeoutMs'] ?? 3000;
        $maxServers = $options['maxServers'] ?? 5000;
        $maxPages = $options['maxPages'] ?? 40;

        $socket = @stream_socket_client("udp://{$host}:{$port}", $errno, $errstr, $timeoutMs / 1000.0);
        if ($socket === false) {
            return [];
        }
        stream_set_blocking($socket, true);
        stream_set_timeout($socket, intdiv($timeoutMs, 1000), ($timeoutMs % 1000) * 1000);

        $all = [];
        $seed = '0.0.0.0:0';
        for ($page = 0; $page < $maxPages; $page++) {
            $req = "\x31" . chr($region) . $seed . "\x00" . $filter . "\x00";
            if (@fwrite($socket, $req) === false) {
                break;
            }
            $data = @fread($socket, 65536);
            if ($data === false || $data === '') {
                break;
            }
            $batch = self::parseBatch($data);
            foreach ($batch['servers'] as $s) {
                $all[] = $s;
                if (count($all) >= $maxServers) {
                    @fclose($socket);
                    return array_values(array_unique($all));
                }
            }
            if ($batch['done'] || $batch['last'] === null) {
                break;
            }
            $seed = $batch['last'];
        }

        @fclose($socket);

        return array_values(array_unique($all));
    }
}
