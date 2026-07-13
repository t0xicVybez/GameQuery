/** A single server to query: its protocol, address, an optional caller tag, and per-server options. */
export class Server {
  constructor(
    public readonly protocol: string,
    public readonly host: string,
    public readonly port: number,
    public readonly id: unknown = null,
    public readonly options: Record<string, unknown> = {},
  ) {}

  /** Parse a `host:port` string into a Server. */
  static fromAddress(
    protocol: string,
    address: string,
    id: unknown = null,
    options: Record<string, unknown> = {},
  ): Server {
    const idx = address.lastIndexOf(':');
    if (idx <= 0 || idx === address.length - 1) {
      throw new Error(`Address '${address}' must be in host:port form`);
    }
    const host = address.slice(0, idx);
    const port = Number(address.slice(idx + 1));
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
