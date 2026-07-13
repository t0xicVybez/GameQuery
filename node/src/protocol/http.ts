/** Minimal HTTP/1.1 response helpers shared by the Palworld and FiveM protocols. */

const SEPARATOR = Buffer.from('\r\n\r\n');

/** True once a full response body (per Content-Length) has arrived. */
export function isHttpComplete(buffer: Buffer): boolean {
  const headerEnd = buffer.indexOf(SEPARATOR);
  if (headerEnd === -1) return false;

  const headers = buffer.subarray(0, headerEnd).toString('latin1');
  const match = headers.match(/^Content-Length:\s*(\d+)/im);
  if (match) {
    const bodyBytes = buffer.length - headerEnd - SEPARATOR.length;
    return bodyBytes >= Number(match[1]);
  }
  // No Content-Length to gate on — treat headers arriving as complete enough.
  return true;
}

/** Split a raw HTTP response into its status code and body string. */
export function splitHttp(raw: Buffer): { status: number; body: string } {
  const headerEnd = raw.indexOf(SEPARATOR);
  const headers = (headerEnd !== -1 ? raw.subarray(0, headerEnd) : raw).toString('latin1');
  const body = headerEnd !== -1 ? raw.subarray(headerEnd + SEPARATOR.length).toString('utf8') : '';

  const match = headers.match(/^HTTP\/\d\.\d\s+(\d+)/);
  return { status: match ? Number(match[1]) : 0, body };
}
