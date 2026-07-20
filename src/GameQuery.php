<?php

declare(strict_types=1);

namespace GameQuery;

use GameQuery\Exception\GameQueryException;

/**
 * Entry point for the library.
 *
 * Basic usage:
 *
 *   $gq = new GameQuery();
 *   $gq->addServer('source', '127.0.0.1:27015', id: 'my-css-server');
 *   $gq->addServer('minecraft', 'mc.example.com:25565');
 *   $results = $gq->process();
 *
 *   foreach ($results as $result) {
 *       echo $result->server->label() . ': ' . ($result->online ? 'online' : 'offline') . "\n";
 *   }
 */
final class GameQuery
{
    private ProtocolRegistry $protocols;

    /** @var list<Server> */
    private array $servers = [];

    private float $timeoutSeconds;
    private int $retries;
    private int $maxConcurrent;

    /**
     * @param int $timeoutMs     Per-step timeout in milliseconds (matches the Node port's unit).
     * @param int $retries       Extra attempts after the first (total attempts = retries + 1).
     * @param int $maxConcurrent Cap on sockets open at once; 0 = unlimited. Set a bound
     *                           (e.g. 256) when polling very large fleets -- PHP's
     *                           stream_select() can't watch more than ~1024 sockets at once.
     */
    public function __construct(int $timeoutMs = 2000, int $retries = 1, int $maxConcurrent = 0)
    {
        $this->protocols = new ProtocolRegistry();
        $this->timeoutSeconds = $timeoutMs / 1000.0;
        $this->retries = $retries;
        $this->maxConcurrent = $maxConcurrent;
    }

    /**
     * @param string $protocol One of 'source', 'source-players', 'source-full', 'minecraft',
     *                         'palworld', or a name you've registered with registerProtocol().
     * @param string $address  "host:port"
     * @param mixed  $id       Optional caller-supplied tag echoed back on the Result (e.g. a DB row id).
     * @param array  $options  Per-server protocol config, e.g. ['password' => '...'] for Palworld.
     *                         See the relevant Protocol class's docblock for the keys it reads.
     */
    public function addServer(string $protocol, string $address, mixed $id = null, array $options = []): self
    {
        $this->servers[] = Server::fromAddress($protocol, $address, $id, $options);
        return $this;
    }

    public function addServerObject(Server $server): self
    {
        $this->servers[] = $server;
        return $this;
    }

    /**
     * Add a server by game id ("rust", "cs2", "minecraft"), resolving the
     * protocol and default port from the game database. Pass $port to override.
     *
     * @param array<string,mixed> $options
     */
    public function addGame(string $game, string $host, ?int $port = null, mixed $id = null, array $options = []): self
    {
        $info = Games::info($game);
        if ($info === null) {
            throw new GameQueryException("Unknown game '{$game}'. See Games::GAMES for supported ids.");
        }

        return $this->addServer($info['protocol'], "{$host}:" . ($port ?? $info['port']), $id, $options);
    }

    /** Register a custom protocol implementation under a name for use with addServer(). */
    public function registerProtocol(string $name, callable $factory): self
    {
        $this->protocols->register($name, $factory);
        return $this;
    }

    /**
     * Queries every added server concurrently and returns one Result per
     * server, in the same order they were added. Always returns a Result
     * for every server, online or not -- there's nothing to catch here.
     *
     * @return list<Result>
     */
    public function process(): array
    {
        $jobs = $this->buildJobs();
        if ($jobs === []) {
            return [];
        }

        // Run in windows when a concurrency cap is set; otherwise all at once.
        $window = $this->maxConcurrent > 0 ? $this->maxConcurrent : count($jobs);

        $results = [];
        foreach (array_chunk($jobs, $window) as $chunk) {
            $manager = new Transport\SocketManager($this->timeoutSeconds, $this->retries);
            foreach ($manager->run($chunk) as $result) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Like process(), but yields each Result the moment its server answers --
     * completion order, not add order. Handy for dashboards that render servers
     * as they come back instead of waiting for the slowest.
     *
     *   foreach ($gq->processStream() as $result) { ... }
     *
     * @return \Generator<int, Result>
     */
    public function processStream(): \Generator
    {
        $jobs = $this->buildJobs();
        if ($jobs === []) {
            return;
        }

        $window = $this->maxConcurrent > 0 ? $this->maxConcurrent : count($jobs);

        foreach (array_chunk($jobs, $window) as $chunk) {
            $manager = new Transport\SocketManager($this->timeoutSeconds, $this->retries);
            yield from $manager->runStream($chunk);
        }
    }

    /**
     * @return list<array{server: Server, protocol: Protocol\ProtocolInterface}>
     */
    private function buildJobs(): array
    {
        $jobs = [];
        foreach ($this->servers as $server) {
            $jobs[] = [
                'server' => $server,
                'protocol' => $this->protocols->get($server->protocol),
            ];
        }

        return $jobs;
    }

    /**
     * Query a single server and return its one Result -- the common case without
     * the addServer()/process() ceremony. Uses a fresh instance so it's safe to
     * call statically.
     *
     * @param array<string,mixed> $options
     * @param array{timeoutMs?: int, retries?: int} $config
     */
    public static function queryOne(string $protocol, string $address, array $options = [], array $config = []): Result
    {
        $gq = new self($config['timeoutMs'] ?? 2000, $config['retries'] ?? 1);
        $gq->addServer($protocol, $address, null, $options);
        return $gq->process()[0];
    }

    /**
     * Query a single server by game id ("rust", "cs2", ...) without knowing its
     * protocol. Resolves the protocol + default port from the game database;
     * pass $port to override.
     *
     * @param array<string,mixed> $options
     * @param array{timeoutMs?: int, retries?: int} $config
     */
    public static function queryGame(string $game, string $host, ?int $port = null, array $options = [], array $config = []): Result
    {
        $info = Games::info($game);
        if ($info === null) {
            throw new GameQueryException("Unknown game '{$game}'. See Games::GAMES for supported ids.");
        }

        return self::queryOne($info['protocol'], "{$host}:" . ($port ?? $info['port']), $options, $config);
    }

    /**
     * Query a server whose A2S/query port may sit at a small offset from its
     * game port. Tries $basePort + each offset concurrently and returns the
     * first Result that came back online (offsets are tried in order, so put the
     * most likely first), or the base-port Result if none answered. The winning
     * Result's server->id is the port that responded.
     *
     * Note: some games (Rust, for one) let admins pick an arbitrary query port
     * with no fixed relationship to the game port -- offset probing can't find
     * those; pass the real query port directly instead.
     *
     * @param list<int>           $offsets Query-port offsets relative to $basePort.
     * @param array<string,mixed> $options
     * @param array{timeoutMs?: int, retries?: int} $config
     */
    public static function queryWithPortProbe(
        string $protocol,
        string $host,
        int $basePort,
        array $offsets = [0, 1, -1],
        array $options = [],
        array $config = [],
    ): Result {
        $gq = new self($config['timeoutMs'] ?? 2000, $config['retries'] ?? 1);

        $ports = [];
        foreach ($offsets as $offset) {
            $port = $basePort + $offset;
            if ($port >= 1 && $port <= 65535 && !in_array($port, $ports, true)) {
                $ports[] = $port;
                $gq->addServer($protocol, "{$host}:{$port}", $port, $options);
            }
        }

        $results = $gq->process();
        foreach ($results as $result) {
            if ($result->online) {
                return $result;
            }
        }

        return $results[0];
    }

    /**
     * Discover Source/A2S servers via the Steam master server (a LIST, not a
     * single-server query). Returns "ip:port" strings to feed into
     * addServer('source', ...). Filter uses Valve's backslash syntax, e.g.
     * '\appid\730'. See SteamMaster for details.
     *
     * @param array{master?: string, masterPort?: int, timeoutMs?: int, maxServers?: int, maxPages?: int} $options
     * @return list<string>
     */
    public static function listServers(string $filter = '', int $region = SteamMaster::REGION_ALL, array $options = []): array
    {
        return SteamMaster::listServers($filter, $region, $options);
    }

    /** Clears the queued server list so the same GameQuery instance can be reused for another batch. */
    public function reset(): self
    {
        $this->servers = [];
        return $this;
    }

    /** @return list<Server> */
    public function getServers(): array
    {
        return $this->servers;
    }
}
