import { AbstractProtocol } from './AbstractProtocol.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * Legacy Minecraft: Java Edition Server List Ping, for servers running 1.6 and
 * older (1.7 switched to the modern VarInt/JSON protocol handled by Minecraft).
 * Still useful for old modpack and nostalgia servers.
 *
 * Conversation (single request, TCP, default port 25565):
 *   -> \xFE\x01
 *   <- \xFF <len:uint16 BE, in UTF-16 code units> <UTF-16BE payload>
 *
 * Two payload shapes are handled:
 *   1.4-1.6:  "§1\0<protocol>\0<version>\0<motd>\0<players>\0<max>"
 *   beta-1.3: "<motd>§<players>§<max>"   (no leading §1, § as separator)
 */
export class MinecraftLegacy extends AbstractProtocol {
  static protocolName(): string {
    return 'minecraft-legacy';
  }

  transport(): Transport {
    return 'tcp';
  }

  initialStep(_server: Server): Step {
    return { tag: 'ping', packet: Buffer.from([0xfe, 0x01]) };
  }

  nextStep(): Step | null {
    return null;
  }

  isResponseComplete(buffer: Buffer): boolean {
    if (buffer.length < 3) return false; // not even the 0xFF marker + length yet
    if (buffer[0] !== 0xff) return true; // not a legacy kick packet; parse() rejects it
    const units = buffer.readUInt16BE(1);
    return buffer.length >= 3 + units * 2;
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const raw = this.responseFor(history, 'ping');
    if (raw === null || raw.length < 3 || raw[0] !== 0xff) return {};

    const units = raw.readUInt16BE(1);
    const payload = this.decodeUtf16Be(raw.subarray(3, 3 + units * 2));

    if (payload.startsWith('§1')) {
      // 1.4-1.6: §1 \0 protocol \0 version \0 motd \0 players \0 max
      const parts = payload.split('\0');
      return {
        name: parts[3] ?? 'Minecraft Server',
        version: parts[2] ?? null,
        protocol_version: parts[1] !== undefined ? parseInt(parts[1], 10) : null,
        players: parseInt(parts[4] ?? '0', 10),
        max_players: parseInt(parts[5] ?? '0', 10),
        players_list: [],
      };
    }

    // beta-1.3: motd § players § max
    const parts = payload.split('§');
    return {
      name: parts[0] ?? 'Minecraft Server',
      players: parseInt(parts[1] ?? '0', 10),
      max_players: parseInt(parts[2] ?? '0', 10),
      players_list: [],
    };
  }

  /** Decode a UTF-16BE byte string, handling surrogate pairs via Node's ucs2. */
  private decodeUtf16Be(bytes: Buffer): string {
    const even = bytes.length - (bytes.length % 2);
    return Buffer.from(bytes.subarray(0, even)).swap16().toString('utf16le');
  }
}
