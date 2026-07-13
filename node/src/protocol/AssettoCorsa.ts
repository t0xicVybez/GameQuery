import { AbstractProtocol } from './AbstractProtocol.js';
import { isHttpComplete, splitHttp } from './http.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * Assetto Corsa dedicated server. The server exposes a small HTTP JSON status
 * interface (the "HTTP port", distinct from the UDP game port — pass the HTTP
 * port here). GET /INFO returns a single JSON object with the server name,
 * client count, max clients, and current track. /INFO carries no per-driver
 * names, so players_list is empty.
 */
export class AssettoCorsa extends AbstractProtocol {
  static protocolName(): string {
    return 'assettocorsa';
  }

  transport(): Transport {
    return 'tcp';
  }

  initialStep(server: Server): Step {
    return { tag: 'info', packet: this.buildRequest(server, '/INFO') };
  }

  nextStep(): Step | null {
    return null;
  }

  isResponseComplete(buffer: Buffer): boolean {
    return isHttpComplete(buffer);
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const raw = this.responseFor(history, 'info');
    if (raw === null) return {};

    const { status, body } = splitHttp(raw);
    if (status !== 200) return {};

    let info: Record<string, unknown>;
    try {
      info = JSON.parse(body) as Record<string, unknown>;
    } catch {
      return { parse_error: true };
    }

    const name = info.name;
    return {
      name: typeof name === 'string' ? name : 'Assetto Corsa Server',
      map: info.track ?? null,
      players: Number(info.clients ?? 0),
      max_players: Number(info.maxclients ?? 0),
      players_list: [],
      password: Boolean(info.pass ?? false),
    };
  }

  private buildRequest(server: Server, path: string): Buffer {
    return Buffer.from(
      `GET ${path} HTTP/1.1\r\n` +
        `Host: ${server.host}:${server.port}\r\n` +
        `Accept: application/json\r\n` +
        `User-Agent: GameQuery-Node\r\n` +
        `Connection: close\r\n` +
        `\r\n`,
      'latin1',
    );
  }
}
