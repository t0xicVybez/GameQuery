<?php

declare(strict_types=1);

namespace GameQuery;

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

    public function __construct(float $timeoutSeconds = 2.0, int $retries = 1)
    {
        $this->protocols = new ProtocolRegistry();
        $this->timeoutSeconds = $timeoutSeconds;
        $this->retries = $retries;
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
        $jobs = [];

        foreach ($this->servers as $server) {
            $jobs[] = [
                'server' => $server,
                'protocol' => $this->protocols->get($server->protocol),
            ];
        }

        $manager = new Transport\SocketManager($this->timeoutSeconds, $this->retries);

        return $manager->run($jobs);
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
