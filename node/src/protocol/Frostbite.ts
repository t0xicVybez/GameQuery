import { ByteReader } from '../buffer/ByteReader.js';
import { AbstractProtocol } from './AbstractProtocol.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * Frostbite / RCON word protocol (TCP) used by DICE's Battlefield line —
 * Battlefield 3, Battlefield 4, Battlefield: Bad Company 2, and the Medal of
 * Honor reboots — over their admin/query port.
 *
 * The wire format is a stream of "words". Each packet is:
 *   <sequence:4> <totalSize:4> <numWords:4>  then numWords x [<len:4> <bytes> 0x00]
 * all little-endian. A client request carries sequence 0 (bit 31 = "from
 * server", bit 30 = "is response" are both clear). We issue the unauthenticated
 * `serverInfo` command, whose reply is ["OK", name, players, maxPlayers, mode,
 * map, ...].
 */
export class Frostbite extends AbstractProtocol {
  static protocolName(): string {
    return 'frostbite';
  }

  transport(): Transport {
    return 'tcp';
  }

  initialStep(_server: Server): Step {
    return { tag: 'serverInfo', packet: this.encode(['serverInfo']) };
  }

  nextStep(): Step | null {
    return null;
  }

  isResponseComplete(buffer: Buffer): boolean {
    if (buffer.length < 12) return false;
    return buffer.length >= buffer.readUInt32LE(4);
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const raw = this.responseFor(history, 'serverInfo');
    if (raw === null || raw.length < 12) return {};

    const r = new ByteReader(raw);
    r.skip(8); // sequence + total size
    const numWords = r.readUInt32();

    const words: string[] = [];
    for (let i = 0; i < numWords; i++) {
      const len = r.readUInt32();
      words.push(r.read(len).toString('latin1'));
      r.skip(1); // null terminator
    }

    if (words[0] !== 'OK') return {};

    return {
      name: words[1] ?? '',
      players: parseInt(words[2] ?? '0', 10),
      max_players: parseInt(words[3] ?? '0', 10),
      game: words[4] ?? null, // game mode (e.g. ConquestLarge0)
      map: words[5] ?? null,
      players_list: [],
      raw_words: words,
    };
  }

  /** Encode a command word list into a client-originated Frostbite packet. */
  private encode(words: string[]): Buffer {
    const parts: Buffer[] = [];
    for (const word of words) {
      const wb = Buffer.from(word, 'latin1');
      const len = Buffer.alloc(4);
      len.writeUInt32LE(wb.length, 0);
      parts.push(len, wb, Buffer.from([0]));
    }
    const body = Buffer.concat(parts);

    const header = Buffer.alloc(12);
    header.writeUInt32LE(0, 0); // sequence
    header.writeUInt32LE(12 + body.length, 4); // total size
    header.writeUInt32LE(words.length, 8); // numWords

    return Buffer.concat([header, body]);
  }
}
