import { AbstractProtocol } from './AbstractProtocol.js';
import { isHttpComplete, splitHttp } from './http.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * FiveM / CFX (GTA V multiplayer). Servers expose HTTP JSON endpoints on the
 * game port (default 30120), so this speaks HTTP over TCP like Palworld but
 * without auth. Conversation: GET /info.json -> [GET /players.json]. The live
 * player count comes from the length of /players.json. Server config lives
 * under "vars" on modern builds and historically at the top level; both are
 * checked.
 */
export class FiveM extends AbstractProtocol {
  static protocolName(): string {
    return 'fivem';
  }

  constructor(private readonly includePlayers = true) {
    super();
  }

  transport(): Transport {
    return 'tcp';
  }

  initialStep(server: Server): Step {
    return { tag: 'info', packet: this.buildRequest(server, '/info.json') };
  }

  nextStep(server: Server, history: HistoryEntry[]): Step | null {
    if (this.includePlayers && !this.hasTag(history, 'players')) {
      return { tag: 'players', packet: this.buildRequest(server, '/players.json') };
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
      if (status === 200) {
        try {
          const info = JSON.parse(body) as Record<string, unknown>;
          const vars = (info.vars ?? {}) as Record<string, unknown>;
          const pick = (key: string): unknown => vars[key] ?? info[key];

          const name = pick('sv_projectName') ?? pick('sv_hostname') ?? info.hostname ?? 'FiveM Server';
          result.name = typeof name === 'string' ? name : 'FiveM Server';
          result.map = pick('mapname') ?? null;
          result.gametype = pick('gametype') ?? null;
          result.max_players = Number(pick('sv_maxClients') ?? pick('sv_maxclients') ?? 0);
          if (info.server !== undefined) result.version = info.server;
        } catch {
          /* leave info unset */
        }
      }
    }

    const playersRaw = this.responseFor(history, 'players');
    if (playersRaw !== null) {
      const { status, body } = splitHttp(playersRaw);
      if (status === 200) {
        try {
          const players = JSON.parse(body) as Array<Record<string, unknown>>;
          if (Array.isArray(players)) {
            result.players = players.length;
            result.players_list = players.map((p) => (typeof p === 'object' && p ? (p.name as string) ?? 'unknown' : 'unknown'));
          }
        } catch {
          /* leave players unset */
        }
      }
    }

    return result;
  }

  private buildRequest(server: Server, path: string): Buffer {
    // No Connection: close — /info + /players reuse one keep-alive connection.
    return Buffer.from(
      `GET ${path} HTTP/1.1\r\n` +
        `Host: ${server.host}:${server.port}\r\n` +
        `Accept: application/json\r\n` +
        `User-Agent: GameQuery-Node\r\n` +
        `\r\n`,
      'latin1',
    );
  }
}
