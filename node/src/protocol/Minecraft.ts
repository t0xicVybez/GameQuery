import { ByteReader } from '../buffer/ByteReader.js';
import { ByteWriter } from '../buffer/ByteWriter.js';
import { AbstractProtocol } from './AbstractProtocol.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * Minecraft: Java Edition "Server List Ping" over TCP. Handshake (state=status)
 * and Status Request are sent back to back; the server replies with a single
 * length-prefixed JSON status packet (which can span several TCP reads when it
 * carries a favicon, hence the framing check in isResponseComplete).
 */
export class Minecraft extends AbstractProtocol {
  private static readonly PROTOCOL_VERSION = 767;

  static protocolName(): string {
    return 'minecraft';
  }

  transport(): Transport {
    return 'tcp';
  }

  initialStep(server: Server): Step {
    const handshake = new ByteWriter()
      .writeVarInt(0x00)
      .writeVarInt(Minecraft.PROTOCOL_VERSION)
      .writeMcString(server.host)
      .writeUInt8((server.port >> 8) & 0xff)
      .writeUInt8(server.port & 0xff)
      .writeVarInt(1) // next state: status
      .withVarIntLengthPrefix();

    const statusRequest = new ByteWriter().writeVarInt(0x00).withVarIntLengthPrefix();

    return { tag: 'status', packet: Buffer.concat([handshake, statusRequest]) };
  }

  nextStep(): Step | null {
    return null;
  }

  isResponseComplete(buffer: Buffer): boolean {
    try {
      const r = new ByteReader(buffer);
      const packetLength = r.readVarInt();
      return r.remaining() >= packetLength;
    } catch {
      return false; // not enough bytes yet for the length varint
    }
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const raw = this.responseFor(history, 'status');
    if (raw === null) return {};

    let json: string;
    let packetId: number;
    try {
      const r = new ByteReader(raw);
      r.readVarInt(); // total length (validated already)
      packetId = r.readVarInt();
      const jsonLength = r.readVarInt();
      json = r.read(jsonLength).toString('utf8');
    } catch {
      return { parse_error: true };
    }
    if (packetId !== 0x00) return { parse_error: true };

    let decoded: Record<string, unknown>;
    try {
      decoded = JSON.parse(json);
    } catch {
      return { parse_error: true };
    }

    const description = decoded.description;
    let name: string;
    if (typeof description === 'string') {
      name = description;
    } else if (description && typeof description === 'object') {
      const d = description as Record<string, unknown>;
      name = typeof d.text === 'string' && (d.extra === undefined) ? d.text : this.flattenChat(d);
    } else {
      name = '';
    }

    const version = (decoded.version ?? {}) as Record<string, unknown>;
    const players = (decoded.players ?? {}) as Record<string, unknown>;
    const sample = Array.isArray(players.sample) ? (players.sample as Array<Record<string, unknown>>) : [];

    return {
      name,
      version: (version.name as string) ?? 'unknown',
      protocol: version.protocol ?? null,
      players: players.online ?? 0,
      max_players: players.max ?? 0,
      players_list: sample.map((p) => (p.name as string) ?? 'unknown'),
      has_favicon: decoded.favicon !== undefined,
    };
  }

  private flattenChat(chat: Record<string, unknown>): string {
    let text = typeof chat.text === 'string' ? chat.text : '';
    const extra = Array.isArray(chat.extra) ? chat.extra : [];
    for (const part of extra) {
      text += typeof part === 'object' && part ? ((part as Record<string, unknown>).text as string) ?? '' : String(part);
    }
    return text;
  }
}
