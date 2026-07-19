<?php

declare(strict_types=1);

namespace GameQuery;

use GameQuery\Exception\GameQueryException;

/**
 * A single server to query. Immutable -- build one per server you're polling.
 */
final class Server
{
    public readonly string $host;
    public readonly int $port;
    public readonly string $protocol;

    /**
     * The host resolved to a numeric IP, when a protocol needs the server's
     * address inside its own packet payload (SA-MP, for one). Populated by the
     * transport layer for protocols that opt in via
     * ProtocolInterface::requiresAddressResolution(); null otherwise. Read it
     * through address(), which falls back to host.
     */
    public readonly ?string $resolvedIp;

    /** Arbitrary caller-supplied tag (e.g. a DB id or Discord channel id) echoed back in the Result. */
    public readonly mixed $id;

    /**
     * Per-server protocol config that isn't just host/port -- credentials
     * being the main case (Palworld's REST API needs an admin password;
     * a protocol added later might need an RCON password, an API key,
     * etc.). Deliberately a generic bag rather than dedicated fields so
     * adding a new protocol never means widening this class again.
     * Read the specific keys a protocol expects from its own docblock.
     *
     * @var array<string, mixed>
     */
    public readonly array $options;

    public function __construct(string $protocol, string $host, int $port, mixed $id = null, array $options = [], ?string $resolvedIp = null)
    {
        if ($port < 1 || $port > 65535) {
            throw new GameQueryException("Invalid port: {$port}");
        }

        $this->protocol = $protocol;
        $this->host = $host;
        $this->port = $port;
        $this->id = $id;
        $this->options = $options;
        $this->resolvedIp = $resolvedIp;
    }

    /** Return a copy with the resolved numeric IP set (host and everything else unchanged). */
    public function withResolvedIp(string $ip): self
    {
        return new self($this->protocol, $this->host, $this->port, $this->id, $this->options, $ip);
    }

    /** The numeric IP for protocols that embed the address in their payload; falls back to host. */
    public function address(): string
    {
        return $this->resolvedIp ?? $this->host;
    }

    /** Convenience factory for the "host:port" (or "[ipv6]:port") shorthand. */
    public static function fromAddress(string $protocol, string $address, mixed $id = null, array $options = []): self
    {
        if (str_starts_with($address, '[')) {
            // Bracketed IPv6, e.g. [::1]:27015 -- brackets are stripped from the host.
            $rb = strpos($address, ']');
            if ($rb === false || ($address[$rb + 1] ?? '') !== ':') {
                throw new GameQueryException("Address '{$address}' must be in [ipv6]:port form");
            }
            $host = substr($address, 1, $rb - 1);
            $portStr = substr($address, $rb + 2);
        } else {
            // Split on the last colon so an unbracketed IPv6-with-port still works.
            $pos = strrpos($address, ':');
            if ($pos === false || $pos === 0 || $pos === strlen($address) - 1) {
                throw new GameQueryException("Address '{$address}' must be in host:port form");
            }
            $host = substr($address, 0, $pos);
            $portStr = substr($address, $pos + 1);
        }

        if (!ctype_digit($portStr)) {
            throw new GameQueryException("Address '{$address}' has an invalid port");
        }

        return new self($protocol, $host, (int) $portStr, $id, $options);
    }

    /** Human-readable identifier for logs/errors -- the caller id, or host:port. */
    public function label(): string
    {
        return $this->id !== null && $this->id !== ''
            ? (string) $this->id
            : "{$this->host}:{$this->port}";
    }
}
