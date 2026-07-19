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

  /**
   * @param includePing When true, follow the status exchange with the SLP
   *   ping/pong (packet 0x01) and report its round trip as data.ping_ms — a
   *   purer network latency than the connect+status time. Costs an extra round
   *   trip, and pre-1.7 / non-responding servers make it time out (the status
   *   data still comes back). Off by default (the `minecraft` protocol);
   *   `minecraft-ping` turns it on.
   */
  constructor(private readonly includePing = false) {
    super();
  }

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

  nextStep(_server: Server, history: HistoryEntry[]): Step | null {
    if (!this.includePing) return null;
    if (!this.hasTag(history, 'status') || this.hasTag(history, 'ping')) return null;

    // SLP ping (0x01) carrying our send-time as the 8-byte payload; the server
    // echoes it in the pong, so parse() can recover the round-trip time without
    // any per-step timing from the transport.
    const payload = Buffer.alloc(8);
    payload.writeBigUInt64BE(BigInt(Date.now()));
    const ping = new ByteWriter().writeVarInt(0x01).writeRaw(payload).withVarIntLengthPrefix();
    return { tag: 'ping', packet: ping };
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
      name = typeof d.text === 'string' && d.extra === undefined ? d.text : this.flattenChat(d);
    } else {
      name = '';
    }

    const version = (decoded.version ?? {}) as Record<string, unknown>;
    const players = (decoded.players ?? {}) as Record<string, unknown>;
    const sample = Array.isArray(players.sample) ? (players.sample as Array<Record<string, unknown>>) : [];

    const result: Record<string, unknown> = {
      name,
      version: (version.name as string) ?? 'unknown',
      protocol: version.protocol ?? null,
      players: players.online ?? 0,
      max_players: players.max ?? 0,
      players_list: sample.map((p) => (p.name as string) ?? 'unknown'),
      has_favicon: decoded.favicon !== undefined,
    };

    // Recover the SLP ping/pong round trip from the echoed send-time, if we ran it.
    const pong = this.responseFor(history, 'ping');
    if (pong !== null) {
      try {
        const r = new ByteReader(pong);
        r.readVarInt(); // packet length
        if (r.readVarInt() === 0x01 && r.remaining() >= 8) {
          const sent = Number(r.read(8).readBigUInt64BE(0));
          result.ping_ms = Math.max(0, Date.now() - sent);
        }
      } catch {
        /* no usable pong; leave ping_ms unset */
      }
    }

    return result;
  }

  private flattenChat(chat: Record<string, unknown>): string {
    let text = typeof chat.text === 'string' ? chat.text : '';
    const extra = Array.isArray(chat.extra) ? chat.extra : [];
    for (const part of extra) {
      text +=
        typeof part === 'object' && part
          ? (((part as Record<string, unknown>).text as string) ?? '')
          : String(part);
    }
    return text;
  }
}
