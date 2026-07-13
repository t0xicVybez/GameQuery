import { ByteReader } from '../buffer/ByteReader.js';
import { AbstractProtocol } from './AbstractProtocol.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * GameSpy protocol version 2 (UDP) — Halo (PC), Battlefield 1942 / Vietnam,
 * Neverwinter Nights, SWAT 4, Star Wars Battlefront, and others.
 *
 * Reply: \x00 <instanceId:4>, then server rules (key\0value\0... ending on an
 * empty key), then a player section (field count, field names, rows). Rules
 * are parsed exactly; the roster is best-effort. First datagram only.
 */
export class GameSpy2 extends AbstractProtocol {
  private static readonly INSTANCE_ID = Buffer.from([0x04, 0x05, 0x06, 0x07]);

  static protocolName(): string {
    return 'gamespy2';
  }

  transport(): Transport {
    return 'udp';
  }

  initialStep(_server: Server): Step {
    // \xFF (rules) \xFF (players) \x00 (skip teams)
    return { tag: 'info', packet: Buffer.concat([Buffer.from([0xfe, 0xfd, 0x00]), GameSpy2.INSTANCE_ID, Buffer.from([0xff, 0xff, 0x00])]) };
  }

  nextStep(): Step | null {
    return null;
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const raw = this.responseFor(history, 'info');
    if (raw === null || raw.length < 5) return {};

    const r = new ByteReader(raw);
    r.skip(5); // type + instance id

    const cvars: Record<string, string> = {};
    while (!r.eof()) {
      const key = r.readCString();
      if (key === '') break;
      cvars[key] = r.readCString();
    }

    const playersList = this.parsePlayers(r);
    const numPlayers = cvars.numplayers !== undefined ? Number(cvars.numplayers) : playersList.length;

    const result: Record<string, unknown> = {
      name: cvars.hostname ?? 'GameSpy Server',
      map: cvars.mapname ?? null,
      max_players: cvars.maxplayers !== undefined ? Number(cvars.maxplayers) : 0,
      players: numPlayers,
      players_list: playersList,
      password_protected: cvars.password !== undefined ? Boolean(Number(cvars.password)) : false,
      rules: cvars,
    };
    if (cvars.gametype) result.gametype = cvars.gametype;
    if (cvars.gamever) result.version = cvars.gamever;
    return result;
  }

  private parsePlayers(r: ByteReader): string[] {
    if (r.eof()) return [];
    try {
      const fieldCount = r.readUInt8();
      if (fieldCount === 0 || fieldCount > 32) return [];

      const fields: string[] = [];
      for (let i = 0; i < fieldCount; i++) {
        fields.push(r.readCString().replace(/_$/, ''));
      }
      let nameIndex = fields.indexOf('player');
      if (nameIndex === -1) nameIndex = 0;

      const names: string[] = [];
      while (!r.eof()) {
        const row: string[] = [];
        for (let i = 0; i < fieldCount; i++) {
          if (r.eof()) return names;
          row.push(r.readCString());
        }
        if ((row[nameIndex] ?? '') === '') break;
        names.push(row[nameIndex] as string);
      }
      return names;
    } catch {
      return [];
    }
  }
}
