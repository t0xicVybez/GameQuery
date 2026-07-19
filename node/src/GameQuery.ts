import { ProtocolRegistry, type ProtocolFactory } from './ProtocolRegistry.js';
import { Server } from './Server.js';
import { SocketManager, type Job } from './transport/SocketManager.js';
import { SteamMaster, type SteamMasterOptions } from './SteamMaster.js';
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

  /**
   * Query a server whose A2S/query port may sit at a small offset from its game
   * port. Tries `basePort` + each offset concurrently and resolves the first
   * Result that came back online (offsets are tried in order), or the base-port
   * Result if none answered. The winning Result's `server.id` is the port that
   * responded.
   *
   * Note: some games (Rust, for one) let admins pick an arbitrary query port
   * with no fixed relationship to the game port — offset probing can't find
   * those; pass the real query port directly instead.
   */
  static async queryWithPortProbe(
    protocol: string,
    host: string,
    basePort: number,
    offsets: number[] = [0, 1, -1],
    options: Record<string, unknown> = {},
    config: { timeoutMs?: number; retries?: number } = {},
  ): Promise<Result> {
    const gq = new GameQuery(config.timeoutMs ?? 2000, config.retries ?? 1);

    const seen = new Set<number>();
    for (const offset of offsets) {
      const port = basePort + offset;
      if (port >= 1 && port <= 65535 && !seen.has(port)) {
        seen.add(port);
        gq.addServer(protocol, `${host}:${port}`, port, options);
      }
    }

    const results = await gq.process();
    return results.find((r) => r.online) ?? results[0]!;
  }

  /**
   * Discover Source/A2S servers via the Steam master server (a LIST, not a
   * single-server query). Returns "ip:port" strings to feed into
   * addServer('source', …). Filter uses Valve's backslash syntax, e.g.
   * '\\appid\\730'. See SteamMaster for details.
   */
  static listServers(
    filter = '',
    region: number = SteamMaster.REGION_ALL,
    options: SteamMasterOptions = {},
  ): Promise<string[]> {
    return SteamMaster.listServers(filter, region, options);
  }

  reset(): this {
    this.servers = [];
    return this;
  }

  getServers(): Server[] {
    return this.servers;
  }
}
