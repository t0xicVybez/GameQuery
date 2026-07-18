import { ByteReader } from '../buffer/ByteReader.js';
import { AbstractProtocol } from './AbstractProtocol.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * id Tech 4 "getInfo" query (UDP) — Doom 3, Quake 4, Enemy Territory: Quake
 * Wars, and Prey. Two-byte 0xFF out-of-band marker; server variables use the
 * si_* naming. The player roster after the variables is parsed best-effort.
 */
export class Doom3 extends AbstractProtocol {
  private static readonly OOB = Buffer.from([0xff, 0xff]);

  static protocolName(): string {
    return 'doom3';
  }

  transport(): Transport {
    return 'udp';
  }

  initialStep(_server: Server): Step {
    return {
      tag: 'info',
      packet: Buffer.concat([Doom3.OOB, Buffer.from('getInfo\x00\x00\x00\x00\x00', 'latin1')]),
    };
  }

  nextStep(): Step | null {
    return null;
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const raw = this.responseFor(history, 'info');
    if (raw === null) return {};

    const marker = raw.indexOf('infoResponse');
    if (marker === -1) return {};

    // Skip "infoResponse\0" then the 4-byte challenge echo.
    const r = new ByteReader(raw.subarray(marker + 'infoResponse'.length + 1));
    r.skip(4);

    const cvars: Record<string, string> = {};
    while (!r.eof()) {
      const key = r.readCString();
      if (key === '') break;
      cvars[key] = r.readCString();
    }

    const players = this.parsePlayers(r);
    const numPlayers = cvars.si_numPlayers !== undefined ? Number(cvars.si_numPlayers) : players.length;

    const result: Record<string, unknown> = {
      name: cvars.si_name ?? 'Doom3 Server',
      map: cvars.si_map ?? null,
      max_players: cvars.si_maxPlayers !== undefined ? Number(cvars.si_maxPlayers) : 0,
      players: numPlayers,
      players_list: players,
      password_protected: cvars.si_usePass !== undefined ? Boolean(Number(cvars.si_usePass)) : false,
      rules: cvars,
    };
    if (cvars.gamename) result.game = cvars.gamename;
    if (cvars.si_version) result.version = cvars.si_version;
    return result;
  }

  private parsePlayers(r: ByteReader): string[] {
    const names: string[] = [];
    while (!r.eof()) {
      try {
        const id = r.readUInt8();
        if (id === 0x20 || r.remaining() < 3) break;
        r.readUInt16(); // ping
        r.readUInt32(); // rate
        const name = r.readCString();
        if (name !== '') names.push(name);
      } catch {
        break;
      }
    }
    return names;
  }
}
