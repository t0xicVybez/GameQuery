<?php

declare(strict_types=1);

namespace GameQuery\Protocol;

/**
 * Minimal HTTP/1.1 response helpers shared by the HTTP-over-TCP protocols
 * (FiveM, Palworld, Terraria, Assetto Corsa). Mirrors the Node port's http.ts:
 * completion detection over Content-Length *or* chunked transfer encoding, and
 * a splitter that de-chunks the body.
 */
final class Http
{
    private const SEPARATOR = "\r\n\r\n";
    private const CHUNKED = '/^Transfer-Encoding:[^\r\n]*\bchunked\b/mi';

    /** True once a full response body (per Content-Length or chunked framing) has arrived. */
    public static function isComplete(string $buffer): bool
    {
        $headerEnd = strpos($buffer, self::SEPARATOR);
        if ($headerEnd === false) {
            return false;
        }

        $headers = substr($buffer, 0, $headerEnd);
        $body = substr($buffer, $headerEnd + 4);

        if (preg_match('/^Content-Length:\s*(\d+)/mi', $headers, $matches)) {
            return strlen($body) >= (int) $matches[1];
        }
        if (preg_match(self::CHUNKED, $headers)) {
            // Wait for the zero-length terminator — otherwise we'd advance the
            // conversation mid-stream and read the rest of this body as the next reply.
            return self::decodeChunked($body) !== null;
        }

        // No framing header — a bodyless response, or one delimited by connection
        // close (the transport reads to EOF). Headers arriving is as complete as we get.
        return true;
    }

    /**
     * Split a raw HTTP response into [status code, body], de-chunking the body
     * when the response used Transfer-Encoding: chunked.
     *
     * @return array{0: int, 1: string}
     */
    public static function split(string $raw): array
    {
        $headerEnd = strpos($raw, self::SEPARATOR);
        $headers = $headerEnd !== false ? substr($raw, 0, $headerEnd) : $raw;
        $body = $headerEnd !== false ? substr($raw, $headerEnd + 4) : '';

        if (preg_match(self::CHUNKED, $headers)) {
            $decoded = self::decodeChunked($body);
            if ($decoded !== null) {
                $body = $decoded; // leave a partial body as-is; json_decode will reject it
            }
        }

        $status = 0;
        if (preg_match('#^HTTP/\d\.\d\s+(\d+)#', $headers, $matches)) {
            $status = (int) $matches[1];
        }

        return [$status, $body];
    }

    /**
     * Walk a Transfer-Encoding: chunked body. Returns the decoded body once the
     * terminating zero-length chunk has arrived, or null if more bytes are still
     * needed (a partial read). Trailers after the final chunk are ignored.
     */
    private static function decodeChunked(string $body): ?string
    {
        $out = '';
        $pos = 0;
        $len = strlen($body);
        while (true) {
            $crlf = strpos($body, "\r\n", $pos);
            if ($crlf === false) {
                return null; // chunk-size line not fully here yet
            }
            $sizeHex = trim(explode(';', substr($body, $pos, $crlf - $pos))[0]);
            if ($sizeHex === '' || !ctype_xdigit($sizeHex)) {
                return null; // malformed framing — wait for more / let it time out
            }
            $size = (int) hexdec($sizeHex);
            $dataStart = $crlf + 2;
            if ($size === 0) {
                return $out; // final chunk — body complete
            }
            $dataEnd = $dataStart + $size;
            if ($dataEnd + 2 > $len) {
                return null; // chunk data (+ its trailing CRLF) not all here
            }
            $out .= substr($body, $dataStart, $size);
            $pos = $dataEnd + 2; // skip the CRLF that follows the chunk data
        }
    }
}
