/** Builder for outgoing binary packets — the counterpart to ByteReader. */
export class ByteWriter {
  private chunks: Buffer[] = [];

  writeUInt8(value: number): this {
    this.chunks.push(Buffer.from([value & 0xff]));
    return this;
  }

  writeInt32(value: number): this {
    const b = Buffer.alloc(4);
    b.writeInt32LE(value | 0, 0);
    this.chunks.push(b);
    return this;
  }

  writeRaw(bytes: Buffer | string): this {
    this.chunks.push(typeof bytes === 'string' ? Buffer.from(bytes, 'utf8') : bytes);
    return this;
  }

  writeCString(value: string): this {
    this.chunks.push(Buffer.from(value + '\x00', 'utf8'));
    return this;
  }

  /** Unsigned LEB128 varint, mirroring ByteReader.readVarInt(). */
  writeVarInt(value: number): this {
    let v = value >>> 0;
    const out: number[] = [];
    do {
      let byte = v & 0x7f;
      v >>>= 7;
      if (v !== 0) {
        byte |= 0x80;
      }
      out.push(byte);
    } while (v !== 0);
    this.chunks.push(Buffer.from(out));
    return this;
  }

  /** Minecraft-style string: VarInt UTF-8 byte length, then the bytes. */
  writeMcString(value: string): this {
    const bytes = Buffer.from(value, 'utf8');
    this.writeVarInt(bytes.length);
    this.chunks.push(bytes);
    return this;
  }

  toBuffer(): Buffer {
    return Buffer.concat(this.chunks);
  }

  /** Wraps the current buffer with a leading VarInt length prefix (Minecraft framing). */
  withVarIntLengthPrefix(): Buffer {
    const body = this.toBuffer();
    return new ByteWriter().writeVarInt(body.length).writeRaw(body).toBuffer();
  }
}
