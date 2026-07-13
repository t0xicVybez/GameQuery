import { ByteReader } from '../buffer/ByteReader.js';
import { AbstractProtocol } from './AbstractProtocol.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * Unreal Engine 2 query protocol (UDP) — UT2003/2004, Killing Floor, Red
 * Orchestra, America's Army 2, and other UE2 titles. Details (0x00) then
 * players (0x02). Strings are length-prefixed (1 byte incl. null); integers
 * are little-endian. UCS-2 "unicode" names (high-bit length) are read
 * best-effort.
 */
export class Unreal2 extends AbstractProtocol {
  static protocolName(): string {
    return 'unreal2';
  }

  constructor(private readonly includePlayers = true) {
    super();
  }

  transport(): Transport {
    return 'udp';
  }

  initialStep(_server: Server): Step {
    return { tag: 'details', packet: Buffer.from([0x79, 0x00, 0x00, 0x00, 0x00]) };
  }

  nextStep(_server: Server, history: HistoryEntry[]): Step | null {
    if (this.includePlayers && !this.hasTag(history, 'players')) {
      return { tag: 'players', packet: Buffer.from([0x79, 0x00, 0x00, 0x00, 0x02]) };
    }
    return null;
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const result: Record<string, unknown> = {};

    const details = this.responseFor(history, 'details');
    if (details !== null) {
      Object.assign(result, this.parseDetails(details));
    }
    const players = this.responseFor(history, 'players');
    if (players !== null) {
      const list = this.parsePlayers(players);
      result.players_list = list;
      if (result.players === undefined) result.players = list.length;
    }
    return result;
  }

  private parseDetails(raw: Buffer): Record<string, unknown> {
    const r = new ByteReader(raw);
    r.skip(5);
    try {
      r.readInt32(); // server id
      this.readUStr(r); // ip
      r.readInt32(); // game port
      r.readInt32(); // query port
      const name = this.readUStr(r);
      const map = this.readUStr(r);
      const gametype = this.readUStr(r);
      const players = r.readInt32();
      const maxPlayers = r.readInt32();
      return {
        name: name !== '' ? name : 'Unreal2 Server',
        map: map !== '' ? map : null,
        gametype: gametype !== '' ? gametype : null,
        players,
        max_players: maxPlayers,
      };
    } catch {
      return { name: 'Unreal2 Server' };
    }
  }

  private parsePlayers(raw: Buffer): string[] {
    const r = new ByteReader(raw);
    r.skip(5);
    const names: string[] = [];
    while (!r.eof()) {
      try {
        r.readInt32(); // id
        const name = this.readUStr(r);
        r.readInt32(); // ping
        r.readInt32(); // score
        r.readInt32(); // stats id
        if (name !== '') names.push(name);
      } catch {
        break;
      }
    }
    return names;
  }

  private readUStr(r: ByteReader): string {
    const len = r.readUInt8();
    if (len === 0) return '';
    if (len > 0x80) {
      const chars = len & 0x7f;
      const bytes = r.read(Math.min(chars * 2, r.remaining()));
      return bytes.toString('utf16le').replace(/\x00+$/, '');
    }
    return r.read(Math.min(len, r.remaining())).toString('utf8').replace(/\x00+$/, '');
  }
}
