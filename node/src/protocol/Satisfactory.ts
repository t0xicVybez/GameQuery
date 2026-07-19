import { ByteReader } from '../buffer/ByteReader.js';
import { AbstractProtocol } from './AbstractProtocol.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * Satisfactory dedicated server — the "Lightweight Query" API (UDP, default
 * port 7777) added in Update 8 / 1.0. One Poll Server State request returns the
 * server name, run state, and build number (Net CL).
 *
 * Player counts are NOT exposed by the lightweight query — those require the
 * authenticated HTTPS API — so players()/maxPlayers() come back null here.
 *
 * Message layout (all little-endian):
 *   request  0xF6D5 <type=0> <ver=1> <cookie:8> <terminator=1>
 *   response 0xF6D5 <type=1> <ver=1> <cookie:8> <state:1> <netCL:4> <flags:8>
 *            <numSubStates:1> [subId:2 subVer:2]* <nameLen:2> <name:utf8>
 */
export class Satisfactory extends AbstractProtocol {
  private static readonly MAGIC = Buffer.from([0xd5, 0xf6]); // 0xF6D5, little-endian
  private static readonly PROTOCOL_VERSION = 1;
  private static readonly COOKIE = Buffer.from([0x47, 0x51, 0x01, 0x00, 0x00, 0x00, 0x00, 0x00]);
  private static readonly STATES = ['offline', 'idle', 'loading', 'playing'];

  static protocolName(): string {
    return 'satisfactory';
  }

  transport(): Transport {
    return 'udp';
  }

  initialStep(_server: Server): Step {
    const packet = Buffer.concat([
      Satisfactory.MAGIC,
      Buffer.from([0x00, Satisfactory.PROTOCOL_VERSION]), // message type 0 (poll) + protocol version
      Satisfactory.COOKIE,
      Buffer.from([0x01]), // message terminator
    ]);
    return { tag: 'state', packet };
  }

  nextStep(): Step | null {
    return null;
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const raw = this.responseFor(history, 'state');
    if (raw === null || raw.length < 25) return {};

    const r = new ByteReader(raw);
    if (r.readUInt16() !== 0xf6d5) return { raw_type: 'bad-magic' };
    if (r.readUInt8() !== 1) return { raw_type: 'not-a-state-response' };
    r.skip(1); // protocol version
    r.skip(8); // cookie (echoed)
    const state = r.readUInt8();
    const netCl = r.readUInt32();
    r.skip(8); // server flags
    const numSubStates = r.readUInt8();
    r.skip(numSubStates * 4); // each sub-state: id(2) + version(2)
    const nameLen = r.readUInt16();
    const name = r.read(nameLen).toString('utf8');

    return {
      name,
      state: Satisfactory.STATES[state] ?? 'unknown',
      state_id: state,
      net_cl: netCl,
      version: String(netCl),
    };
  }
}
