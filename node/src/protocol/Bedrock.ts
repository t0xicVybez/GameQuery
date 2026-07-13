import { ByteReader } from '../buffer/ByteReader.js';
import { AbstractProtocol } from './AbstractProtocol.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * Minecraft: Bedrock Edition, via RakNet's connectionless Unconnected Ping /
 * Unconnected Pong exchange (UDP). Sends 0x01, the server replies 0x1C with a
 * semicolon-delimited MOTD carrying name, versions, and player counts.
 *
 * MOTD fields: 0 edition, 1 motd line 1, 2 protocol, 3 version, 4 players,
 * 5 max players, 6 server GUID, 7 motd line 2, 8 gamemode, ...
 */
export class Bedrock extends AbstractProtocol {
  private static readonly MAGIC = Buffer.from('00ffff00fefefefefdfdfdfd12345678', 'hex');

  static protocolName(): string {
    return 'bedrock';
  }

  transport(): Transport {
    return 'udp';
  }

  initialStep(_server: Server): Step {
    const time = Buffer.alloc(8);
    time.writeBigUInt64BE(BigInt(Date.now()));
    const guid = Buffer.alloc(8);
    guid.writeBigUInt64BE(BigInt(Math.floor(Math.random() * 0xffffffff)));
    return { tag: 'ping', packet: Buffer.concat([Buffer.from([0x01]), time, Bedrock.MAGIC, guid]) };
  }

  nextStep(): Step | null {
    return null;
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const raw = this.responseFor(history, 'ping');
    if (raw === null || raw.length < 35) return {};

    const r = new ByteReader(raw);
    r.skip(1 + 8 + 8 + 16); // id, server time, server GUID, MAGIC
    const length = r.readUInt16BE();
    const motd = r.read(Math.min(length, r.remaining())).toString('utf8');
    const parts = motd.split(';');

    const result: Record<string, unknown> = {
      name: parts[1] ?? 'Bedrock Server',
      edition: parts[0] ?? null,
      version: parts[3] ?? null,
      protocol_version: parts[2] !== undefined ? Number(parts[2]) : null,
      players: parts[4] !== undefined ? Number(parts[4]) : 0,
      max_players: parts[5] !== undefined ? Number(parts[5]) : 0,
    };
    if (parts[7]) result.map = parts[7];
    if (parts[8]) result.gamemode = parts[8];
    return result;
  }
}
