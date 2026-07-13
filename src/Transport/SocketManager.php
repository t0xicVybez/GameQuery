<?php

declare(strict_types=1);

namespace GameQuery\Transport;

use GameQuery\Protocol\ProtocolInterface;
use GameQuery\Result;
use GameQuery\Server;

/**
 * Drives every QuerySession to completion in parallel using a single
 * stream_select() event loop -- one PHP process, no threads, no forking,
 * no external event-loop dependency. This is what makes querying 200
 * servers take roughly as long as querying 1: the wait time for slow/dead
 * servers overlaps instead of stacking up.
 */
final class SocketManager
{
    public function __construct(
        private readonly float $timeoutSeconds = 2.0,
        private readonly int $maxRetries = 1,
    ) {
    }

    /**
     * @param list<array{server: Server, protocol: ProtocolInterface}> $jobs
     * @return list<Result>
     */
    public function run(array $jobs): array
    {
        /** @var list<QuerySession> $sessions */
        $sessions = [];

        foreach ($jobs as $job) {
            $session = new QuerySession($job['server'], $job['protocol'], $this->timeoutSeconds, $this->maxRetries);
            $session->open();
            $sessions[] = $session;
        }

        // Hard ceiling so a pathological case (e.g. a select() that never
        // returns readiness) can't hang the whole batch forever. Individual
        // per-step timeouts should always win first in normal operation.
        $hardDeadline = microtime(true) + $this->timeoutSeconds * ($this->maxRetries + 1) + 2.0;

        while (true) {
            $active = array_filter($sessions, static fn (QuerySession $s) => !$s->isFinished());

            if ($active === []) {
                break;
            }

            if (microtime(true) > $hardDeadline) {
                foreach ($active as $session) {
                    $session->forceTimeout();
                }
                break;
            }

            $read = [];
            $write = [];
            /** @var array<int, QuerySession> $bySocketId */
            $bySocketId = [];

            foreach ($active as $session) {
                $socket = $session->socket();
                if ($socket === null || !is_resource($socket)) {
                    continue;
                }

                if ($session->awaitingConnect()) {
                    $write[] = $socket;
                } else {
                    $read[] = $socket;
                }

                $bySocketId[(int) $socket] = $session;
            }

            if ($read === [] && $write === []) {
                // Nothing left with a live socket to wait on -- let the
                // timeout tick below finish off whatever remains.
                foreach ($active as $session) {
                    $session->tickTimeout();
                }
                continue;
            }

            $except = null;
            // Short poll interval: this is what lets per-session timeouts
            // (which can differ in when they expire) get checked promptly
            // without a dedicated timer per socket.
            $n = @stream_select($read, $write, $except, 0, 200_000);

            if ($n === false) {
                // A closed/invalid resource in the set can trip this. Give
                // every active session a timeout tick; sessions with a dead
                // socket will fail their next send/read and finish cleanly.
                foreach ($active as $session) {
                    $session->tickTimeout();
                }
                continue;
            }

            foreach ($write as $socket) {
                $bySocketId[(int) $socket]?->handleConnectable();
            }

            foreach ($read as $socket) {
                $bySocketId[(int) $socket]?->handleReadable();
            }

            foreach ($active as $session) {
                $session->tickTimeout();
            }
        }

        $results = array_map(static fn (QuerySession $s) => $s->toResult(), $sessions);

        foreach ($sessions as $session) {
            $session->close();
        }

        return $results;
    }
}
