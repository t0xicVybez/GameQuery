import { AbstractProtocol } from './AbstractProtocol.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * QuakeWorld "status" query (UDP) — the original Quake 1 netcode, still spoken
 * by modern QuakeWorld ports (ezQuake, FTE, nQuake) and games built on them.
 * Distinct from the later id Tech 2/3 status protocols: the response marker is
 * a single 'n', the info string keys are unprefixed (hostname, map, maxclients
 * rather than sv_hostname / sv_maxclients), and player lines carry the extra
 * QuakeWorld fields (userid, frags, time, name, skin, colors).
 *
 * Conversation shape (single UDP request/response):
 *   -> \xFF\xFF\xFF\xFF status\n
 *   <- \xFF\xFF\xFF\xFF n \key\value...\n
 *        <userid> <frags> <time> "<name>" "<skin>" <top> <bottom>\n  (per player)
 */
export class QuakeWorld extends AbstractProtocol {
  private static readonly OOB = Buffer.from([0xff, 0xff, 0xff, 0xff]);

  static protocolName(): string {
    return 'quakeworld';
  }

  transport(): Transport {
    return 'udp';
  }

  initialStep(_server: Server): Step {
    return { tag: 'status', packet: Buffer.concat([QuakeWorld.OOB, Buffer.from('status\n', 'latin1')]) };
  }

  nextStep(): Step | null {
    return null; // single-shot
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const raw = this.responseFor(history, 'status');
    if (raw === null) return {};

    // Drop the 0xFF out-of-band header and the leading 'n' response marker.
    let body = raw.toString('latin1').replace(/^\xff+/, '');
    if (body.startsWith('n')) body = body.slice(1);

    const lines = body.split('\n');
    const cvars = this.parseInfoString(lines[0] ?? '');

    const players: string[] = [];
    for (let i = 1; i < lines.length; i++) {
      const line = (lines[i] ?? '').trim();
      if (line === '') continue;
      // <userid> <frags> <time> "name" "skin" <top> <bottom>
      const m = line.match(/^\d+\s+-?\d+\s+\d+\s+"([^"]*)"/);
      if (m) players.push(m[1] ?? '');
    }

    const result: Record<string, unknown> = {
      name: cvars.hostname ?? 'QuakeWorld Server',
      map: cvars.map ?? null,
      max_players: cvars.maxclients ? parseInt(cvars.maxclients, 10) : 0,
      players: players.length,
      players_list: players,
      rules: cvars,
    };
    if (cvars['*gamedir'] !== undefined) result.game = cvars['*gamedir'];
    if (cvars['*version'] !== undefined) result.version = cvars['*version'];

    return result;
  }

  /** Parse a `\key\value\key\value` id Tech info string into a map. */
  private parseInfoString(s: string): Record<string, string> {
    const parts = s.replace(/^\\/, '').split('\\');
    const cvars: Record<string, string> = {};
    for (let i = 0; i + 1 < parts.length; i += 2) {
      cvars[parts[i] ?? ''] = parts[i + 1] ?? '';
    }
    return cvars;
  }
}
