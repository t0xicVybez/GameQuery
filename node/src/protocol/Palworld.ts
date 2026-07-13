import { AbstractProtocol } from './AbstractProtocol.js';
import { isHttpComplete, splitHttp } from './http.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * Palworld's dedicated server exposes a REST API (RESTAPIEnabled=True in
 * PalWorldSettings.ini), not a binary protocol — plain HTTP + Basic Auth over
 * TCP, default port 8212.
 *
 * Required per-server option: `password` (the server's AdminPassword).
 * Optional: `username` (defaults to 'admin').
 *
 * Conversation: GET /v1/api/info -> [GET /v1/api/players]. Read-only endpoints
 * only. Completion relies on Content-Length (no chunked-encoding path).
 */
export class Palworld extends AbstractProtocol {
  static protocolName(): string {
    return 'palworld';
  }

  constructor(private readonly includePlayers = true) {
    super();
  }

  transport(): Transport {
    return 'tcp';
  }

  initialStep(server: Server): Step {
    return { tag: 'info', packet: this.buildRequest(server, '/v1/api/info') };
  }

  nextStep(server: Server, history: HistoryEntry[]): Step | null {
    if (this.includePlayers && !this.hasTag(history, 'players')) {
      return { tag: 'players', packet: this.buildRequest(server, '/v1/api/players') };
    }
    return null;
  }

  isResponseComplete(buffer: Buffer): boolean {
    return isHttpComplete(buffer);
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const result: Record<string, unknown> = {};

    const infoRaw = this.responseFor(history, 'info');
    if (infoRaw !== null) {
      const { status, body } = splitHttp(infoRaw);
      if (status === 401) {
        result.auth_error = true;
      } else if (status === 200) {
        try {
          const info = JSON.parse(body) as Record<string, unknown>;
          if (info.servername !== undefined) result.name = info.servername;
          if (info.version !== undefined) result.version = info.version;
          if (info.description !== undefined) result.description = info.description;
          if (info.worldguid !== undefined) result.world_guid = info.worldguid;
        } catch {
          result.parse_error = true;
        }
      } else if (status !== 0) {
        result.http_error = status;
      }
    }

    const playersRaw = this.responseFor(history, 'players');
    if (playersRaw !== null) {
      const { status, body } = splitHttp(playersRaw);
      if (status === 200) {
        try {
          const decoded = JSON.parse(body) as { players?: Array<Record<string, unknown>> };
          const players = Array.isArray(decoded.players) ? decoded.players : [];
          result.players = players.length;
          result.players_list = players.map((p) => (p.name as string) ?? 'unknown');
        } catch {
          /* leave players unset */
        }
      }
    }

    return result;
  }

  private buildRequest(server: Server, path: string): Buffer {
    const password = server.options.password;
    if (password === undefined || password === null) {
      throw new Error(`Palworld server ${server.label()}: missing required options.password`);
    }
    const username = (server.options.username as string) ?? 'admin';
    const auth = Buffer.from(`${username}:${password}`).toString('base64');

    return Buffer.from(
      `GET ${path} HTTP/1.1\r\n` +
        `Host: ${server.host}:${server.port}\r\n` +
        `Authorization: Basic ${auth}\r\n` +
        `Accept: application/json\r\n` +
        `User-Agent: GameQuery-Node\r\n` +
        `\r\n`,
      'latin1',
    );
  }
}
