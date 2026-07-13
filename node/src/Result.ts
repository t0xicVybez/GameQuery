import type { Server } from './Server.js';

/** The outcome of querying one server. Always produced — online or not. */
export class Result {
  constructor(
    public readonly server: Server,
    public readonly online: boolean,
    public readonly pingMs: number,
    public readonly data: Record<string, unknown> = {},
    public readonly error: string | null = null,
  ) {}

  /** Flat plain-object form (matches the PHP library's JSON shape) for the CLI/JSON output. */
  toObject(): Record<string, unknown> {
    return {
      id: this.server.id,
      host: this.server.host,
      port: this.server.port,
      protocol: this.server.protocol,
      online: this.online,
      ping_ms: this.pingMs,
      error: this.error,
      ...this.data,
    };
  }
}
