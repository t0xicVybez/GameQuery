import { ByteReader } from '../buffer/ByteReader.js';
import { AbstractProtocol } from './AbstractProtocol.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * Mumble voice server ping (UDP) — the connectionless status ping every Murmur /
 * Mumble server answers on its voice port (default 64738). One request, one
 * reply carrying version, user count, and max users (all big-endian). The ping
 * carries no server name.
 */
export class Mumble extends AbstractProtocol {
  static protocolName(): string {
    return 'mumble';
  }

  transport(): Transport {
    return 'udp';
  }

  initialStep(_server: Server): Step {
    const ident = Buffer.alloc(8);
    ident.writeBigUInt64BE(BigInt(Math.floor(Math.random() * 0xffffffff)));
    return { tag: 'ping', packet: Buffer.concat([Buffer.from([0, 0, 0, 0]), ident]) };
  }

  nextStep(): Step | null {
    return null;
  }

  parse(server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const raw = this.responseFor(history, 'ping');
    if (raw === null || raw.length < 24) return {};

    const r = new ByteReader(raw);
    r.skip(1); // leading zero
    const major = r.readUInt8();
    const minor = r.readUInt8();
    const patch = r.readUInt8();
    r.skip(8); // ident echo
    const users = r.readUInt32BE();
    const maxUsers = r.readUInt32BE();
    const bandwidth = r.readUInt32BE();

    return {
      name: `Mumble Server (${server.host})`,
      version: `${major}.${minor}.${patch}`,
      players: users,
      max_players: maxUsers,
      players_list: [],
      bandwidth,
    };
  }
}
