<?php

declare(strict_types=1);

namespace GameQuery\Buffer;

use GameQuery\Exception\GameQueryException;

/**
 * A cursor-based reader over a raw binary string.
 *
 * Game server protocols are almost universally sequences of fixed-width
 * little-endian integers and null-terminated (or length-prefixed, or
 * varint-prefixed) strings. Every protocol class leans on this instead of
 * hand-rolling unpack() calls with off-by-one offsets scattered everywhere.
 */
final class ByteReader
{
    private string $data;
    private int $length;
    private int $offset = 0;

    public function __construct(string $data)
    {
        $this->data = $data;
        $this->length = strlen($data);
    }

    public function remaining(): int
    {
        return max(0, $this->length - $this->offset);
    }

    public function eof(): bool
    {
        return $this->offset >= $this->length;
    }

    public function skip(int $bytes): void
    {
        $this->offset += $bytes;
    }

    /** Read $count raw bytes and advance the cursor. */
    public function read(int $count): string
    {
        if ($count < 0 || $this->offset + $count > $this->length) {
            throw new GameQueryException(sprintf(
                'ByteReader: attempted to read %d bytes with only %d remaining',
                $count,
                $this->remaining()
            ));
        }

        $chunk = substr($this->data, $this->offset, $count);
        $this->offset += $count;

        return $chunk;
    }

    public function readInt8(): int
    {
        $v = unpack('c', $this->read(1))[1];
        return $v;
    }

    public function readUInt8(): int
    {
        return unpack('C', $this->read(1))[1];
    }

    public function readInt16(): int
    {
        return unpack('v', $this->read(2))[1] << 16 >> 16; // sign-extend
    }

    public function readUInt16(): int
    {
        return unpack('v', $this->read(2))[1];
    }

    /** Big-endian unsigned 16-bit, as used by RakNet (Minecraft Bedrock). */
    public function readUInt16BE(): int
    {
        return unpack('n', $this->read(2))[1];
    }

    public function readInt32(): int
    {
        $v = unpack('V', $this->read(4))[1];
        // unpack('V') is unsigned; convert to signed 32-bit on 64-bit PHP builds
        if ($v > 0x7FFFFFFF) {
            $v -= 0x100000000;
        }
        return $v;
    }

    public function readUInt32(): int
    {
        return unpack('V', $this->read(4))[1];
    }

    /** Big-endian unsigned 32-bit (RakNet, Mumble, and other network-order protocols). */
    public function readUInt32BE(): int
    {
        return unpack('N', $this->read(4))[1];
    }

    public function readUInt64(): int
    {
        // Steam IDs etc. PHP ints are 64-bit signed on any modern platform,
        // which covers the full unsigned range we actually encounter here.
        return unpack('P', $this->read(8))[1];
    }

    public function readFloat(): float
    {
        return unpack('g', $this->read(4))[1];
    }

    /** Read a null-terminated string (the C-string convention used by A2S). */
    public function readCString(): string
    {
        $end = strpos($this->data, "\x00", $this->offset);

        if ($end === false) {
            // Malformed / truncated packet -- return what's left rather than throw,
            // so a single bad field doesn't blow up the whole result.
            $rest = substr($this->data, $this->offset);
            $this->offset = $this->length;
            return $rest;
        }

        $str = substr($this->data, $this->offset, $end - $this->offset);
        $this->offset = $end + 1;

        return $str;
    }

    /**
     * Read a protobuf-style unsigned LEB128 varint, as used by the
     * Minecraft (modern) protocol for lengths and packet IDs.
     */
    public function readVarInt(): int
    {
        $result = 0;
        $shift = 0;

        do {
            if ($this->eof()) {
                throw new GameQueryException('ByteReader: truncated VarInt');
            }

            $byte = $this->readUInt8();
            $result |= ($byte & 0x7F) << $shift;
            $shift += 7;

            if ($shift > 35) {
                throw new GameQueryException('ByteReader: VarInt too long');
            }
        } while (($byte & 0x80) !== 0);

        return $result;
    }
}
