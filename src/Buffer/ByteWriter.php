<?php

declare(strict_types=1);

namespace GameQuery\Buffer;

/**
 * Small builder for outgoing binary packets. The counterpart to ByteReader.
 */
final class ByteWriter
{
    private string $buffer = '';

    public function writeUInt8(int $value): static
    {
        $this->buffer .= pack('C', $value & 0xFF);
        return $this;
    }

    public function writeInt32(int $value): static
    {
        $this->buffer .= pack('V', $value & 0xFFFFFFFF);
        return $this;
    }

    public function writeRaw(string $bytes): static
    {
        $this->buffer .= $bytes;
        return $this;
    }

    public function writeCString(string $value): static
    {
        $this->buffer .= $value . "\x00";
        return $this;
    }

    /** Unsigned LEB128 varint, mirroring ByteReader::readVarInt(). */
    public function writeVarInt(int $value): static
    {
        $value &= 0xFFFFFFFF;

        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            if ($value !== 0) {
                $byte |= 0x80;
            }
            $this->buffer .= pack('C', $byte);
        } while ($value !== 0);

        return $this;
    }

    /** Minecraft-style string: VarInt UTF-8 byte length, then the bytes. */
    public function writeMcString(string $value): static
    {
        $this->writeVarInt(strlen($value));
        $this->buffer .= $value;
        return $this;
    }

    public function toString(): string
    {
        return $this->buffer;
    }

    /** Wraps the current buffer with a leading VarInt length prefix (Minecraft framing). */
    public function withVarIntLengthPrefix(): string
    {
        return (new self())->writeVarInt(strlen($this->buffer))->writeRaw($this->buffer)->toString();
    }
}
