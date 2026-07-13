import { ByteReader } from '../buffer/ByteReader.js';
import { AbstractProtocol } from './AbstractProtocol.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * All-Seeing Eye (ASE) query protocol (UDP) — Multi Theft Auto (MTA:SA) and
 * other titles using the ASE browser format. Every field is a length-prefixed
 * string (1 byte length including itself, then length-1 bytes). Reply: EYE1,
 * nine server fields, key/value rules (empty key ends), then per-row players
 * with a flags byte selecting name/team/skin/score/ping.
 */
export class Ase extends AbstractProtocol {
  static protocolName(): string {
    return 'ase';
  }

  transport(): Transport {
    return 'udp';
  }

  initialStep(_server: Server): Step {
    return { tag: 'info', packet: Buffer.from('s', 'latin1') };
  }

  nextStep(): Step | null {
    return null;
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const raw = this.responseFor(history, 'info');
    if (raw === null || raw.length < 4) return {};

    const r = new ByteReader(raw);
    if (r.read(4).toString('latin1') !== 'EYE1') return {};

    const gamename = this.readString(r);
    this.readString(r); // game port
    const hostname = this.readString(r);
    const gametype = this.readString(r);
    const map = this.readString(r);
    const version = this.readString(r);
    const password = this.readString(r);
    const numplayers = this.readString(r);
    const maxplayers = this.readString(r);

    const rules: Record<string, string> = {};
    while (!r.eof()) {
      const key = this.readString(r);
      if (key === '') break;
      rules[key] = this.readString(r);
    }

    const players = this.parsePlayers(r, Number(numplayers) || 0);

    const result: Record<string, unknown> = {
      name: hostname !== '' ? hostname : 'ASE Server',
      map: map !== '' ? map : null,
      max_players: Number(maxplayers) || 0,
      players: Number(numplayers) || 0,
      players_list: players,
      password_protected: Boolean(Number(password)),
      rules,
    };
    if (gametype !== '') result.gametype = gametype;
    if (version !== '') result.version = version;
    if (gamename !== '') result.game = gamename;
    return result;
  }

  private parsePlayers(r: ByteReader, count: number): string[] {
    const names: string[] = [];
    for (let i = 0; i < count && !r.eof(); i++) {
      try {
        const flags = r.readUInt8();
        const name = flags & 0x01 ? this.readString(r) : '';
        if (flags & 0x02) this.readString(r);
        if (flags & 0x04) this.readString(r);
        if (flags & 0x08) this.readString(r);
        if (flags & 0x10) this.readString(r);
        if (name !== '') names.push(name);
      } catch {
        break;
      }
    }
    return names;
  }

  /** ASE length-prefixed string: 1-byte length (incl. itself), then length-1 bytes. */
  private readString(r: ByteReader): string {
    if (r.eof()) return '';
    const len = r.readUInt8();
    if (len <= 1) return '';
    return r.read(Math.min(len - 1, r.remaining())).toString('utf8');
  }
}
