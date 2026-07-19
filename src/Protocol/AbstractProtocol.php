<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

/**
 * Optional base class -- pulls out the bit of bookkeeping every protocol
 * needs (finding a past response by tag) so concrete protocols only have
 * to think about packet layout and sequencing.
 */
abstract class AbstractProtocol implements ProtocolInterface
{
    /**
     * @param list<array{tag: string, request: string, response: string}> $history
     */
    protected function responseFor(array $history, string $tag): ?string
    {
        foreach ($history as $entry) {
            if ($entry['tag'] === $tag) {
                return $entry['response'];
            }
        }

        return null;
    }

    /**
     * @param list<array{tag: string, request: string, response: string}> $history
     */
    protected function hasTag(array $history, string $tag): bool
    {
        return $this->responseFor($history, $tag) !== null;
    }

    /**
     * Default: assume every read is already a complete message. Correct for
     * UDP (each recvfrom() is one datagram). TCP-based protocols override
     * this to inspect their own length framing.
     */
    public function isResponseComplete(string $buffer): bool
    {
        return true;
    }

    /**
     * Default: false. Protocols that embed the server's own numeric IP inside
     * their request payload (SA-MP, for one) override this to true, and the
     * transport layer resolves the host to an IP and exposes it via
     * Server::address() before initialStep() runs.
     */
    public function requiresAddressResolution(): bool
    {
        return false;
    }

    /**
     * Default: false. A protocol whose UDP reply can span several datagrams
     * (A2S, for one) overrides this to true; the transport then hands every
     * datagram of the current step to reassemble() instead of treating the
     * first one as the whole reply. Leaving it false keeps the single-datagram
     * fast path untouched for every other UDP protocol.
     */
    public function supportsMultiPacket(): bool
    {
        return false;
    }

    /**
     * Only called when supportsMultiPacket() is true. Given every datagram
     * received for the current step so far, return the assembled reply once it
     * is complete, or null if more datagrams are still expected. The default
     * simply returns the latest datagram (no reassembly).
     *
     * @param list<string> $fragments
     */
    public function reassemble(array $fragments): ?string
    {
        return $fragments === [] ? null : $fragments[array_key_last($fragments)];
    }
}
