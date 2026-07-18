import { ProtocolRegistry, type ProtocolFactory } from './ProtocolRegistry.js';
import { Server } from './Server.js';
import { SocketManager, type Job } from './transport/SocketManager.js';
import type { Result } from './Result.js';

/**
 * Entry point for the library.
 *
 *   const gq = new GameQuery();
 *   gq.addServer('source', '127.0.0.1:27015', 'my-css-server');
 *   gq.addServer('minecraft', 'mc.example.com:25565');
 *   const results = await gq.process();
 */
export class GameQuery {
  private readonly protocols = new ProtocolRegistry();
  private servers: Server[] = [];

  /**
   * @param timeoutMs      Per-step timeout in milliseconds.
   * @param retries        Extra attempts after the first (total attempts = retries + 1).
   * @param maxConcurrent  Cap on sockets open at once; 0 = unlimited. Set a bound
   *                       when polling very large fleets to avoid exhausting file
   *                       descriptors / ephemeral ports.
   */
  constructor(
    private readonly timeoutMs = 2000,
    private readonly retries = 1,
    private readonly maxConcurrent = 0,
  ) {}

  /**
   * @param protocol  A registered protocol name, e.g. 'source', 'minecraft', 'palworld'.
   * @param address   "host:port"
   * @param id        Optional caller tag echoed back on the Result.
   * @param options   Per-server config, e.g. { password: '...' } for Palworld.
   */
  addServer(
    protocol: string,
    address: string,
    id: unknown = null,
    options: Record<string, unknown> = {},
  ): this {
    this.servers.push(Server.fromAddress(protocol, address, id, options));
    return this;
  }

  addServerObject(server: Server): this {
    this.servers.push(server);
    return this;
  }

  registerProtocol(name: string, factory: ProtocolFactory): this {
    this.protocols.register(name, factory);
    return this;
  }

  /** Queries every added server concurrently; one Result per server, in order. */
  async process(): Promise<Result[]> {
    const jobs: Job[] = this.servers.map((server) => ({
      server,
      protocol: this.protocols.get(server.protocol),
    }));
    if (jobs.length === 0) return [];

    // Run in windows when a concurrency cap is set; otherwise all at once.
    const window = this.maxConcurrent > 0 ? this.maxConcurrent : jobs.length;
    const results: Result[] = [];
    for (let i = 0; i < jobs.length; i += window) {
      const manager = new SocketManager(this.timeoutMs, this.retries);
      results.push(...(await manager.run(jobs.slice(i, i + window))));
    }
    return results;
  }

  /**
   * Query a single server and resolve its one Result — the common case without
   * the addServer()/process() ceremony.
   */
  static async queryOne(
    protocol: string,
    address: string,
    options: Record<string, unknown> = {},
    config: { timeoutMs?: number; retries?: number } = {},
  ): Promise<Result> {
    const gq = new GameQuery(config.timeoutMs ?? 2000, config.retries ?? 1);
    gq.addServer(protocol, address, null, options);
    return (await gq.process())[0]!;
  }

  reset(): this {
    this.servers = [];
    return this;
  }

  getServers(): Server[] {
    return this.servers;
  }
}
