/** A single server to query: its protocol, address, an optional caller tag, and per-server options. */
export class Server {
  constructor(
    public readonly protocol: string,
    public readonly host: string,
    public readonly port: number,
    public readonly id: unknown = null,
    public readonly options: Record<string, unknown> = {},
    /**
     * The host resolved to a numeric IP, when a protocol needs the server's
     * address inside its own packet payload (SA-MP, for one). Populated by the
     * transport layer for protocols that opt in via
     * ProtocolInterface.requiresAddressResolution(); null otherwise. Read it
     * through address(), which falls back to host.
     */
    public readonly resolvedIp: string | null = null,
  ) {}

  /** Return a copy with the resolved numeric IP set (host and everything else unchanged). */
  withResolvedIp(ip: string): Server {
    return new Server(this.protocol, this.host, this.port, this.id, this.options, ip);
  }

  /** The numeric IP for protocols that embed the address in their payload; falls back to host. */
  address(): string {
    return this.resolvedIp ?? this.host;
  }

  /** Parse a `host:port` string (or `[ipv6]:port`) into a Server. */
  static fromAddress(
    protocol: string,
    address: string,
    id: unknown = null,
    options: Record<string, unknown> = {},
  ): Server {
    let host: string;
    let portStr: string;
    if (address.startsWith('[')) {
      // Bracketed IPv6, e.g. [::1]:27015 — brackets are stripped from the host.
      const rb = address.indexOf(']');
      if (rb === -1 || address[rb + 1] !== ':') {
        throw new Error(`Address '${address}' must be in [ipv6]:port form`);
      }
      host = address.slice(1, rb);
      portStr = address.slice(rb + 2);
    } else {
      // Split on the last colon so an unbracketed IPv6-with-port still works.
      const idx = address.lastIndexOf(':');
      if (idx <= 0 || idx === address.length - 1) {
        throw new Error(`Address '${address}' must be in host:port form`);
      }
      host = address.slice(0, idx);
      portStr = address.slice(idx + 1);
    }
    const port = Number(portStr);
    if (!Number.isInteger(port) || port < 1 || port > 65535) {
      throw new Error(`Address '${address}' has an invalid port`);
    }
    return new Server(protocol, host, port, id, options);
  }

  /** Human-readable identifier for logs/errors — the caller id, or host:port. */
  label(): string {
    return this.id != null && this.id !== '' ? String(this.id) : `${this.host}:${this.port}`;
  }
}
