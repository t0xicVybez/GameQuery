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

  constructor(
    private readonly timeoutMs = 2000,
    private readonly retries = 1,
  ) {}

  /**
   * @param protocol  A registered protocol name, e.g. 'source', 'minecraft', 'palworld'.
   * @param address   "host:port"
   * @param id        Optional caller tag echoed back on the Result.
   * @param options   Per-server config, e.g. { password: '...' } for Palworld.
   */
  addServer(protocol: string, address: string, id: unknown = null, options: Record<string, unknown> = {}): this {
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
    const manager = new SocketManager(this.timeoutMs, this.retries);
    return manager.run(jobs);
  }

  reset(): this {
    this.servers = [];
    return this;
  }

  getServers(): Server[] {
    return this.servers;
  }
}
