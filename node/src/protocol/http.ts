/** Minimal HTTP/1.1 response helpers shared by the Palworld and FiveM protocols. */

const SEPARATOR = Buffer.from('\r\n\r\n');
const CHUNKED = /^Transfer-Encoding:[^\r\n]*\bchunked\b/im;

/**
 * Walk a `Transfer-Encoding: chunked` body. Returns the decoded body once the
 * terminating zero-length chunk has arrived, or `null` if more bytes are still
 * needed (a partial read). Trailers after the final chunk are ignored.
 */
function decodeChunked(body: Buffer): Buffer | null {
  const out: Buffer[] = [];
  let pos = 0;
  for (;;) {
    const crlf = body.indexOf('\r\n', pos);
    if (crlf === -1) return null; // chunk-size line not fully here yet
    const size = parseInt((body.subarray(pos, crlf).toString('latin1').split(';')[0] ?? '').trim(), 16);
    if (Number.isNaN(size)) return null; // malformed framing — wait for more / let it time out
    const dataStart = crlf + 2;
    if (size === 0) return Buffer.concat(out); // final chunk — body complete
    const dataEnd = dataStart + size;
    if (dataEnd + 2 > body.length) return null; // chunk data (+ its trailing CRLF) not all here
    out.push(body.subarray(dataStart, dataEnd));
    pos = dataEnd + 2; // skip the CRLF that follows the chunk data
  }
}

/** True once a full response body (per Content-Length or chunked framing) has arrived. */
export function isHttpComplete(buffer: Buffer): boolean {
  const headerEnd = buffer.indexOf(SEPARATOR);
  if (headerEnd === -1) return false;

  const headers = buffer.subarray(0, headerEnd).toString('latin1');
  const body = buffer.subarray(headerEnd + SEPARATOR.length);

  const match = headers.match(/^Content-Length:\s*(\d+)/im);
  if (match) {
    return body.length >= Number(match[1]);
  }
  if (CHUNKED.test(headers)) {
    // Wait for the zero-length terminator — otherwise we'd advance the
    // conversation mid-stream and read the rest of this body as the next reply.
    return decodeChunked(body) !== null;
  }
  // No framing header — a bodyless response, or one delimited by connection
  // close (the transport reads to EOF). Headers arriving is as complete as we get.
  return true;
}

/** Split a raw HTTP response into its status code and body string (de-chunked if needed). */
export function splitHttp(raw: Buffer): { status: number; body: string } {
  const headerEnd = raw.indexOf(SEPARATOR);
  const headers = (headerEnd !== -1 ? raw.subarray(0, headerEnd) : raw).toString('latin1');
  let bodyBuf = headerEnd !== -1 ? raw.subarray(headerEnd + SEPARATOR.length) : Buffer.alloc(0);

  if (CHUNKED.test(headers)) {
    const decoded = decodeChunked(bodyBuf);
    if (decoded !== null) bodyBuf = decoded; // leave a partial body as-is; JSON.parse will reject it
  }

  const match = headers.match(/^HTTP\/\d\.\d\s+(\d+)/);
  return { status: match ? Number(match[1]) : 0, body: bodyBuf.toString('utf8') };
}
