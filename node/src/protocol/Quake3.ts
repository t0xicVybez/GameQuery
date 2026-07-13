import { AbstractProtocol } from './AbstractProtocol.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * id Tech 3 "getstatus" query (UDP) — Quake 3/Live, all Call of Duty 1/2/4/UO/
 * WaW, OpenArena, Xonotic, Warsow, Urban Terror, Wolfenstein: Enemy Territory,
 * Jedi Academy, and more. Single request/response; the reply is a leading
 * 0xFF*4 out-of-band marker, then "statusResponse", then a backslash-delimited
 * cvar line, then one player line each ("<score> <ping> \"name\"").
 */
export class Quake3 extends AbstractProtocol {
  private static readonly OOB = Buffer.from([0xff, 0xff, 0xff, 0xff]);

  static protocolName(): string {
    return 'quake3';
  }

  transport(): Transport {
    return 'udp';
  }

  initialStep(_server: Server): Step {
    return { tag: 'status', packet: Buffer.concat([Quake3.OOB, Buffer.from('getstatus\n', 'latin1')]) };
  }

  nextStep(): Step | null {
    return null;
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const raw = this.responseFor(history, 'status');
    if (raw === null) return {};

    const body = stripOob(raw);
    const lines = body.split('\n');
    lines.shift(); // "statusResponse"

    const cvars = parseInfoString(lines[0] ?? '');
    const players: Array<{ score: number; ping: number; name: string }> = [];
    for (let i = 1; i < lines.length; i++) {
      const line = (lines[i] ?? '').trim();
      const m = line.match(/^(-?\d+)\s+(\d+)\s+"(.*)"$/);
      if (m) players.push({ score: Number(m[1]), ping: Number(m[2]), name: m[3] ?? '' });
    }

    const result: Record<string, unknown> = {
      name: cvars.sv_hostname ?? 'Quake3 Server',
      map: cvars.mapname ?? null,
      max_players: cvars.sv_maxclients !== undefined ? Number(cvars.sv_maxclients) : 0,
      players: players.length,
      players_list: players.map((p) => p.name),
      password_protected: cvars.g_needpass !== undefined ? Boolean(Number(cvars.g_needpass)) : false,
      rules: cvars,
    };
    if (cvars.gamename) result.game = cvars.gamename;
    if (cvars.g_gametype) result.gametype = cvars.g_gametype;
    return result;
  }
}

/** Strip the leading 0xFF out-of-band bytes and decode the rest as UTF-8. */
export function stripOob(raw: Buffer): string {
  let i = 0;
  while (i < raw.length && raw[i] === 0xff) i++;
  return raw.subarray(i).toString('utf8');
}

/** Parse a `\key\value\key\value` id Tech info string into a map. */
export function parseInfoString(s: string): Record<string, string> {
  const parts = s.replace(/^\\/, '').split('\\');
  const out: Record<string, string> = {};
  for (let i = 0; i + 1 < parts.length; i += 2) {
    out[parts[i] as string] = parts[i + 1] as string;
  }
  return out;
}
