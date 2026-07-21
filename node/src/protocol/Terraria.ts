import { AbstractProtocol } from './AbstractProtocol.js';
import { isHttpComplete, splitHttp } from './http.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * Terraria dedicated servers running the TShock server mod, which exposes a
 * REST API (vanilla Terraria has no query protocol at all). This is plain HTTP
 * over TCP on the REST port (default 7878, distinct from the 7777 game port).
 *
 * TShock's `/v2/server/status` is a *public* endpoint — it only requires
 * `RestApiEnabled = true` server-side, not a token — so the token is optional:
 *
 *   options.token?: a TShock REST token, appended only when provided (needed
 *                   only if an admin has locked the status endpoint down).
 *
 *   gq.addServer('terraria', '203.0.113.10:7878');                     // anonymous
 *   gq.addServer('terraria', '203.0.113.10:7878', { options: { token } }); // with token
 *
 * Conversation: GET /v2/server/status?players=true[&token=<token>]. The single
 * JSON reply carries the world name, player count, max players, and the
 * connected player list.
 */
export class Terraria extends AbstractProtocol {
  static protocolName(): string {
    return 'terraria';
  }

  transport(): Transport {
    return 'tcp';
  }

  initialStep(server: Server): Step {
    const token = server.options?.token;
    let path = '/v2/server/status?players=true';
    if (typeof token === 'string' && token !== '') {
      path += `&token=${encodeURIComponent(token)}`;
    }
    return { tag: 'status', packet: this.buildRequest(server, path) };
  }

  nextStep(): Step | null {
    return null;
  }

  isResponseComplete(buffer: Buffer): boolean {
    return isHttpComplete(buffer);
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const raw = this.responseFor(history, 'status');
    if (raw === null) return {};

    const { status, body } = splitHttp(raw);
    if (status !== 200) return {};

    let data: Record<string, unknown>;
    try {
      data = JSON.parse(body) as Record<string, unknown>;
    } catch {
      return { parse_error: true };
    }

    // TShock reports its own "status" string ("200" on success).
    if (data.status !== undefined && String(data.status) !== '200') return {};

    const players: string[] = [];
    for (const player of (Array.isArray(data.players) ? data.players : []) as unknown[]) {
      if (player && typeof player === 'object' && 'nickname' in player) {
        players.push(String((player as Record<string, unknown>).nickname));
      } else if (typeof player === 'string') {
        players.push(player);
      }
    }

    const name = data.name;
    return {
      name: typeof name === 'string' ? name : 'Terraria Server',
      map: data.world ?? null,
      players: Number(data.playercount ?? players.length),
      max_players: Number(data.maxplayers ?? 0),
      version: data.serverversion ?? null,
      players_list: players,
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
