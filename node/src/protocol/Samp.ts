import { ByteReader } from '../buffer/ByteReader.js';
import { AbstractProtocol } from './AbstractProtocol.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * SA-MP (San Andreas Multiplayer) and open.mp, the GTA: San Andreas multiplayer
 * mods. Their query is a connectionless UDP protocol on the game port (default
 * 7777). Every request echoes the server's own address back for anti-spoofing:
 *
 *   "SAMP" <ip:4 octets> <port:2 LE> <opcode>
 *
 * so the protocol needs the host resolved to a numeric IP first (see
 * requiresAddressResolution). Opcode 'i' returns core info; 'c' returns the
 * client list (name + score). Large servers deliberately omit the client list,
 * so players_list may be empty even when the server is full.
 */
export class Samp extends AbstractProtocol {
  constructor(private readonly includePlayers = true) {
    super();
  }

  static protocolName(): string {
    return 'samp';
  }

  transport(): Transport {
    return 'udp';
  }

  requiresAddressResolution(): boolean {
    return true;
  }

  initialStep(server: Server): Step {
    return { tag: 'info', packet: this.buildPacket(server, 'i') };
  }

  nextStep(server: Server, history: HistoryEntry[]): Step | null {
    if (this.includePlayers && !this.hasTag(history, 'players')) {
      return { tag: 'players', packet: this.buildPacket(server, 'c') };
    }
    return null;
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const result: Record<string, unknown> = {};

    const info = this.responseFor(history, 'info');
    if (info !== null && info.length > 11) {
      const r = new ByteReader(info.subarray(11)); // skip the echoed 11-byte header
      const password = r.readUInt8();
      const players = r.readUInt16();
      const maxPlayers = r.readUInt16();
      const name = r.read(r.readUInt32()).toString('latin1');
      const gamemode = r.read(r.readUInt32()).toString('latin1');
      const language = r.read(r.readUInt32()).toString('latin1');

      result.name = name;
      result.gametype = gamemode;
      result.language = language;
      result.players = players;
      result.max_players = maxPlayers;
      result.password = password === 1;
      result.players_list = [];
    }

    const playersRaw = this.responseFor(history, 'players');
    if (playersRaw !== null && playersRaw.length > 11) {
      const r = new ByteReader(playersRaw.subarray(11));
      const count = r.readUInt16();
      const list: string[] = [];
      for (let i = 0; i < count; i++) {
        list.push(r.read(r.readUInt8()).toString('latin1')); // 1-byte length + name
        r.skip(4); // score (int32)
      }
      result.players_list = list;
    }

    return result;
  }

  private buildPacket(server: Server, opcode: string): Buffer {
    const octets = server
      .address()
      .split('.')
      .map((o) => parseInt(o, 10) & 0xff);
    const ip = octets.length === 4 ? octets : [0, 0, 0, 0];

    const packet = Buffer.alloc(11);
    packet.write('SAMP', 0, 'latin1');
    packet[4] = ip[0]!;
    packet[5] = ip[1]!;
    packet[6] = ip[2]!;
    packet[7] = ip[3]!;
    packet.writeUInt16LE(server.port, 8);
    packet.write(opcode, 10, 'latin1');
    return packet;
  }
}
