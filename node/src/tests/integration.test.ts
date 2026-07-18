/**
 * Live integration check — NOT part of the unit suite (smoke.test.ts).
 *
 * This actually goes out over the network and queries real, public game
 * servers, so results depend on those servers being up and reachable from
 * wherever you run it. It never fails the process on an offline server (a down
 * server is data, not a bug); it prints a table and always exits 0. Use it to
 * sanity-check a protocol end to end against something real.
 *
 *   npm run test:integration
 *
 * The default list sticks to endpoints that are reliably online. Add your own
 * targets — one per protocol you want to smoke-test live — as
 * [protocol, host:port, label, options?].
 */
import { GameQuery } from '../GameQuery.js';

type Target = [protocol: string, address: string, label: string, options?: Record<string, unknown>];

const targets: Target[] = [
  ['minecraft', 'mc.hypixel.net:25565', 'Hypixel (Java)'],
  ['minecraft', 'play.cubecraft.net:25565', 'CubeCraft (Java)'],
  ['bedrock', 'geo.hivebedrock.network:19132', 'The Hive (Bedrock)'],
  ['bedrock', 'play.cubecraft.net:19132', 'CubeCraft (Bedrock)'],

  // --- Add your own below (these are placeholders; edit or delete) -----------
  // ['source', '1.2.3.4:27015', 'My CS2 server'],
  // ['fivem', '1.2.3.4:30120', 'My FiveM server'],
  // ['palworld', '1.2.3.4:8212', 'My Palworld', { password: 'adminpw' }],
  // ['samp', '1.2.3.4:7777', 'My SA-MP server'],
  // ['teamspeak3', '1.2.3.4:10011', 'My TS3 (query port must be open)', { voicePort: 9987 }],
];

function pad(s: string, width: number): string {
  return s.length >= width ? s.slice(0, width) : s + ' '.repeat(width - s.length);
}

async function main(): Promise<void> {
  const gq = new GameQuery(3000, 1); // milliseconds — same unit as the PHP port
  for (const [protocol, address, label, options] of targets) {
    gq.addServer(protocol, address, label, options ?? {});
  }

  const start = Date.now();
  const results = await gq.process();
  const elapsed = Date.now() - start;

  console.log(
    `${pad('label', 22)} ${pad('protocol', 9)} ${pad('up', 4)} ${pad('ping', 9)}  ${pad('name', 30)} players`,
  );
  console.log('-'.repeat(100));

  let online = 0;
  for (const r of results) {
    if (r.online) online++;
    const rawName = typeof r.data.name === 'string' ? r.data.name : '';
    const name = rawName.replace(/[\u0000-\u001f]/g, '');
    const players =
      r.data.players !== undefined ? `${r.data.players}/${r.data.max_players ?? '?'}` : (r.error ?? '-');

    console.log(
      `${pad(String(r.server.id), 22)} ${pad(r.server.protocol, 9)} ${pad(r.online ? 'yes' : 'no', 4)} ${pad(
        `${r.pingMs.toFixed(1)}ms`,
        9,
      )}  ${pad(name, 30)} ${players}`,
    );
  }

  console.log(`\n${online}/${results.length} online — queried concurrently in ${elapsed}ms total.`);
  process.exit(0);
}

void main();
