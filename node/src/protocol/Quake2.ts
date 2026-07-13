import { AbstractProtocol } from './AbstractProtocol.js';
import { parseInfoString, stripOob } from './Quake3.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * id Tech 2 "status" query (UDP) — Quake 2 and its source ports / mods.
 * Structurally the id Tech 3 reply, but introduced by "print" and using the
 * older unprefixed cvar names (hostname, mapname, maxclients).
 */
export class Quake2 extends AbstractProtocol {
  private static readonly OOB = Buffer.from([0xff, 0xff, 0xff, 0xff]);

  static protocolName(): string {
    return 'quake2';
  }

  transport(): Transport {
    return 'udp';
  }

  initialStep(_server: Server): Step {
    return { tag: 'status', packet: Buffer.concat([Quake2.OOB, Buffer.from('status\n', 'latin1')]) };
  }

  nextStep(): Step | null {
    return null;
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const raw = this.responseFor(history, 'status');
    if (raw === null) return {};

    const lines = stripOob(raw).split('\n');
    lines.shift(); // "print"

    const cvars = parseInfoString(lines[0] ?? '');
    const players: string[] = [];
    for (let i = 1; i < lines.length; i++) {
      const m = (lines[i] ?? '').trim().match(/^(-?\d+)\s+(\d+)\s+"(.*)"$/);
      if (m) players.push(m[3] ?? '');
    }

    const result: Record<string, unknown> = {
      name: cvars.hostname ?? 'Quake2 Server',
      map: cvars.mapname ?? null,
      max_players: cvars.maxclients !== undefined ? Number(cvars.maxclients) : 0,
      players: players.length,
      players_list: players,
      rules: cvars,
    };
    if (cvars.gamename) result.game = cvars.gamename;
    return result;
  }
}
