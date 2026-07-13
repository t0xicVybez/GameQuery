/**
 * Cursor-based reader over a binary Buffer — the Node counterpart to the PHP
 * ByteReader. Game protocols are sequences of fixed-width little-endian
 * integers and null-terminated / length-prefixed / varint strings; every
 * protocol leans on this instead of hand-rolling offset math.
 */
export class ByteReader {
  private offset = 0;

  constructor(private readonly data: Buffer) {}

  remaining(): number {
    return Math.max(0, this.data.length - this.offset);
  }

  eof(): boolean {
    return this.offset >= this.data.length;
  }

  skip(bytes: number): void {
    this.offset += bytes;
  }

  /** Read `count` raw bytes and advance the cursor. */
  read(count: number): Buffer {
    if (count < 0 || this.offset + count > this.data.length) {
      throw new Error(`ByteReader: attempted to read ${count} bytes with only ${this.remaining()} remaining`);
    }
    const chunk = this.data.subarray(this.offset, this.offset + count);
    this.offset += count;
    return chunk;
  }

  readInt8(): number {
    return this.read(1).readInt8(0);
  }

  readUInt8(): number {
    return this.read(1).readUInt8(0);
  }

  readInt16(): number {
    return this.read(2).readInt16LE(0);
  }

  readUInt16(): number {
    return this.read(2).readUInt16LE(0);
  }

  /** Big-endian unsigned 16-bit, as used by RakNet (Minecraft Bedrock). */
  readUInt16BE(): number {
    return this.read(2).readUInt16BE(0);
  }

  readInt32(): number {
    return this.read(4).readInt32LE(0);
  }

  readUInt32(): number {
    return this.read(4).readUInt32LE(0);
  }

  /** Unsigned 64-bit little-endian (Steam IDs etc.) as a decimal string, to stay JSON-safe. */
  readUInt64(): string {
    return this.read(8).readBigUInt64LE(0).toString();
  }

  readFloat(): number {
    return this.read(4).readFloatLE(0);
  }

  /** Read a null-terminated string (the C-string convention used by A2S). UTF-8 decoded. */
  readCString(): string {
    const end = this.data.indexOf(0x00, this.offset);
    if (end === -1) {
      // Malformed / truncated — return what's left rather than throw.
      const rest = this.data.subarray(this.offset);
      this.offset = this.data.length;
      return rest.toString('utf8');
    }
    const str = this.data.subarray(this.offset, end).toString('utf8');
    this.offset = end + 1;
    return str;
  }

  /** Protobuf-style unsigned LEB128 varint, used by the modern Minecraft protocol. */
  readVarInt(): number {
    let result = 0;
    let shift = 0;
    let byte: number;
    do {
      if (this.eof()) {
        throw new Error('ByteReader: truncated VarInt');
      }
      byte = this.readUInt8();
      result |= (byte & 0x7f) << shift;
      shift += 7;
      if (shift > 35) {
        throw new Error('ByteReader: VarInt too long');
      }
    } while ((byte & 0x80) !== 0);
    return result >>> 0;
  }
}
