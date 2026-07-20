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
        $results = [];
        foreach ($this->drive($jobs) as [$index, $result]) {
            $results[$index] = $result;
        }
        ksort($results);

        return array_values($results);
    }

    /**
     * Like run(), but yields each Result the moment its server finishes
     * (completion order, not add order).
     *
     * @param list<array{server: Server, protocol: ProtocolInterface}> $jobs
     * @return \Generator<int, Result>
     */
    public function runStream(array $jobs): \Generator
    {
        foreach ($this->drive($jobs) as [, $result]) {
            yield $result;
        }
    }

    /**
     * Drives every session to completion on the single stream_select() loop,
     * yielding [addIndex, Result] the instant each one finishes. Shared by
     * run() (which reorders back to add order) and runStream().
     *
     * @param list<array{server: Server, protocol: ProtocolInterface}> $jobs
     * @return \Generator<int, array{0: int, 1: Result}>
     */
    private function drive(array $jobs): \Generator
    {
        /** @var list<QuerySession> $sessions */
        $sessions = [];
        foreach ($jobs as $job) {
            $session = new QuerySession($job['server'], $job['protocol'], $this->timeoutSeconds, $this->maxRetries);
            $session->open();
            $sessions[] = $session;
        }

        $yielded = array_fill(0, count($sessions), false);

        // Hard ceiling so a pathological case (e.g. a select() that never
        // returns readiness) can't hang the whole batch forever. Individual
        // per-step timeouts should always win first in normal operation.
        $hardDeadline = microtime(true) + $this->timeoutSeconds * ($this->maxRetries + 1) + 2.0;

        while (true) {
            // Emit any sessions that finished on the previous tick.
            foreach ($sessions as $i => $session) {
                if (!$yielded[$i] && $session->isFinished()) {
                    $yielded[$i] = true;
                    $result = $session->toResult();
                    $session->close();
                    yield [$i, $result];
                }
            }

            $active = array_filter($sessions, static fn (QuerySession $s) => !$s->isFinished());
            if ($active === []) {
                break;
            }

            if (microtime(true) > $hardDeadline) {
                foreach ($active as $session) {
                    $session->forceTimeout();
                }
                continue; // loop once more to emit the force-finished sessions
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

        // Emit anything finished by the final tick / hard deadline.
        foreach ($sessions as $i => $session) {
            if (!$yielded[$i]) {
                $yielded[$i] = true;
                $result = $session->toResult();
                $session->close();
                yield [$i, $result];
            }
        }
    }
}
