import { AbstractProtocol } from './AbstractProtocol.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * GameSpy protocol version 1 (UDP) — the original text query: Unreal, UT99/GOTY,
 * Deus Ex, Tribes 2, Serious Sam, and other late-90s/early-2000s titles.
 * One flat `\key\value\...\final\` reply; server and per-player fields (player_0,
 * frags_0, ...) share a namespace, reassembled by numeric suffix.
 *
 * Reads the first datagram only; very full servers can split the roster across
 * packets tagged with a trailing `\queryid\<id>.<n>`.
 */
export class GameSpy1 extends AbstractProtocol {
  static protocolName(): string {
    return 'gamespy1';
  }

  transport(): Transport {
    return 'udp';
  }

  initialStep(_server: Server): Step {
    return { tag: 'status', packet: Buffer.from('\\status\\', 'latin1') };
  }

  nextStep(): Step | null {
    return null;
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const raw = this.responseFor(history, 'status');
    if (raw === null) return {};

    const parts = raw.toString('utf8').replace(/^\\/, '').split('\\');
    const cvars: Record<string, string> = {};
    const players: Record<number, string> = {};

    for (let i = 0; i + 1 < parts.length; i += 2) {
      const key = parts[i] as string;
      const value = parts[i + 1] as string;
      if (key === 'final' || key === 'queryid') continue;
      const pm = key.match(/^player_(\d+)$/);
      if (pm) {
        players[Number(pm[1])] = value;
      } else {
        cvars[key] = value;
      }
    }

    const playersList = Object.keys(players)
      .map(Number)
      .sort((a, b) => a - b)
      .map((k) => players[k] as string);

    const result: Record<string, unknown> = {
      name: cvars.hostname ?? 'GameSpy Server',
      map: cvars.mapname ?? null,
      max_players: cvars.maxplayers !== undefined ? Number(cvars.maxplayers) : 0,
      players: cvars.numplayers !== undefined ? Number(cvars.numplayers) : playersList.length,
      players_list: playersList,
      password_protected: cvars.password !== undefined ? Boolean(Number(cvars.password)) : false,
      rules: cvars,
    };
    if (cvars.gametype) result.gametype = cvars.gametype;
    if (cvars.gamever) result.version = cvars.gamever;
    return result;
  }
}
