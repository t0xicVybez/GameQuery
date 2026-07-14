<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

use GameQuery\Server;

/**
 * A protocol is modeled as a linear conversation: send one packet, look at
 * the reply, decide what (if anything) to send next. This covers both
 * single-shot protocols (Minecraft: one request, one JSON reply) and
 * challenge/response protocols (Source A2S: request -> challenge -> re-request
 * with challenge -> real data) without the caller needing to know which
 * kind of protocol it's talking to.
 *
 * Protocol instances must be stateless / side-effect free -- a single
 * instance is shared across every server being queried concurrently. All
 * state for a given server lives in the $history array that gets threaded
 * through each call.
 *
 * SocketManager drives this: it owns the sockets and timing, the protocol
 * class owns byte layout and decision-making.
 */
interface ProtocolInterface
{
    /** 'udp' or 'tcp'. */
    public function transport(): string;

    /** Human-readable identifier, e.g. 'source', 'minecraft'. Used as the addServer() key. */
    public static function name(): string;

    /**
     * The first step of the conversation.
     *
     * @return array{tag: string, packet: string}
     */
    public function initialStep(Server $server): array;

    /**
     * Given everything sent/received so far, decide the next step.
     * Return null when the conversation is complete -- at that point
     * parse() is called and the connection is closed.
     *
     * @param list<array{tag: string, request: string, response: string}> $history
     * @return array{tag: string, packet: string}|null
     */
    public function nextStep(Server $server, array $history): ?array;

    /**
     * Turn the full conversation history into the final structured result
     * data. Called once nextStep() returns null.
     *
     * @param list<array{tag: string, request: string, response: string}> $history
     * @return array<string, mixed>
     */
    public function parse(Server $server, array $history): array;

    /**
     * For UDP this is always true -- one recvfrom() call is one complete
     * datagram. For TCP a single read() may return only part of a framed
     * packet (Minecraft's favicon-laden status responses routinely span
     * several reads), so stream protocols inspect the length prefix
     * themselves to know when to stop reading and hand off to parse().
     */
    public function isResponseComplete(string $buffer): bool;

    /**
     * Whether this protocol needs the server's host resolved to a numeric IP
     * before initialStep() -- true only for protocols that embed the server
     * address in their own request payload (SA-MP). When true, the transport
     * layer resolves the host and exposes it via Server::address().
     * AbstractProtocol returns false.
     */
    public function requiresAddressResolution(): bool;
}
