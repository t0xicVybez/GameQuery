/* Malformed-input fuzzer — throws random/garbage buffers at every protocol's
 * parse / nextStep / isResponseComplete / reassemble and asserts none of them
 * crash with an unexpected exception. A remote game server is untrusted input,
 * so a hostile or buggy reply must never take down the process. Run: npm run fuzz
 *
 * The only sanctioned throw is ByteReader running past the end of the buffer
 * (the transport catches that and reports the server offline); anything else —
 * a TypeError, RangeError, undefined access — is a bug to fix. */
import { ProtocolRegistry } from '../ProtocolRegistry.js';
import { Server } from '../Server.js';
import type { HistoryEntry } from '../types.js';

const registry = new ProtocolRegistry();
// Provide every option any protocol might read, so config checks don't fire.
const server = Server.fromAddress('x', '127.0.0.1:25565', 'fuzz', {
  password: 'pw',
  token: 'tok',
  voicePort: 9987,
});
const ITERATIONS = 4000;

const HEADERS: number[][] = [
  [0xff, 0xff, 0xff, 0xff],
  [0xff, 0xff, 0xff, 0xfe],
  [0xfe, 0xfd, 0x09],
  [0xfe, 0xfd, 0x00],
  [0x00, 0x00, 0x00, 0x01],
  [0x1c],
  [0x49],
  [0x44],
  [0x45],
  [0x41],
  [0x09],
];

function randBytes(max: number): Buffer {
  const len = (Math.random() * max) | 0;
  const b = Buffer.alloc(len);
  for (let i = 0; i < len; i++) b[i] = (Math.random() * 256) | 0;
  return b;
}

function fuzzBuffer(): Buffer {
  const r = Math.random();
  if (r < 0.15) return Buffer.alloc(0);
  if (r < 0.3) return randBytes(8);
  if (r < 0.55) {
    const head = HEADERS[(Math.random() * HEADERS.length) | 0] as number[];
    return Buffer.concat([Buffer.from(head), randBytes(400)]);
  }
  if (r < 0.7) {
    return Buffer.from(
      `HTTP/1.1 200 OK\r\nContent-Length: ${(Math.random() * 999) | 0}\r\n\r\n` +
        '{"a":'.repeat((Math.random() * 40) | 0),
      'latin1',
    );
  }
  if (r < 0.85) {
    return Buffer.from('\x00'.repeat((Math.random() * 80) | 0) + '\\a\\b\\c\\player_\x00', 'latin1');
  }
  return randBytes(1600);
}

let unexpected = 0;
let cases = 0;
for (const name of registry.names()) {
  for (let i = 0; i < ITERATIONS; i++) {
    cases++;
    const buf = fuzzBuffer();
    try {
      const proto = registry.get(name);
      proto.isResponseComplete(buf);
      if (proto.supportsMultiPacket()) {
        proto.reassemble([buf]);
        proto.reassemble([buf, fuzzBuffer(), fuzzBuffer()]);
      }
      const step = proto.initialStep(server);
      const history: HistoryEntry[] = [{ tag: step.tag, request: step.packet, response: buf }];
      let next = proto.nextStep(server, history);
      let guard = 0;
      while (next !== null && guard++ < 8) {
        history.push({ tag: next.tag, request: next.packet, response: fuzzBuffer() });
        next = proto.nextStep(server, history);
      }
      proto.parse(server, history);
    } catch (e) {
      const msg = e instanceof Error ? e.message : String(e);
      if (!msg.startsWith('ByteReader:')) {
        unexpected++;
        if (unexpected <= 25) {
          console.log(`UNEXPECTED in ${name}: ${e instanceof Error ? e.constructor.name : typeof e}: ${msg}`);
        }
      }
    }
  }
}

console.log(`\nFuzzed ${cases} cases across ${registry.names().length} protocols`);
console.log(
  unexpected === 0 ? 'Fuzz OK: no unexpected exceptions' : `Fuzz FAIL: ${unexpected} unexpected exceptions`,
);
process.exit(unexpected === 0 ? 0 : 1);
