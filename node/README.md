# GameQuery (Node / TypeScript)

The Node/TypeScript port of [GameQuery](../README.md) — a dependency-free game
server query library. Same architecture and protocols as the PHP version, but
built on Node's async `dgram`/`net` sockets, so concurrency comes for free (no
event-loop of its own) and it embeds natively in a Node app with no subprocess.

## Install

```bash
npm install @t0xicvybez/gamequery
```

## Usage

```ts
import { GameQuery } from '@t0xicvybez/gamequery';

const gq = new GameQuery(/* timeoutMs */ 2000, /* retries */ 1);

gq.addServer('source', '127.0.0.1:27015', 'my-css-server');
gq.addServer('minecraft', 'mc.example.com:25565');
gq.addServer('palworld', '203.0.113.10:8212', 'pal', { password: 'admin-pw' });

for (const r of await gq.process()) {
  if (!r.online) {
    console.log(`${r.server.label()} is offline (${r.error})`);
    continue;
  }
  console.log(`${r.data.name}: ${r.data.players}/${r.data.max_players}, ${r.pingMs}ms`);
}
```

`process()` queries every server concurrently and resolves one `Result` per
server, in order — online or not.

> **Note on the timeout unit:** the constructor takes **milliseconds**
> (`2000` = 2s), following Node convention. The PHP port takes the same unit
> (`new GameQuery(timeoutMs: 2000)`), so an explicit value means the same thing
> in both ports.

## CLI

```bash
gamequery source 127.0.0.1:27015
gamequery minecraft mc.example.com:25565
gamequery palworld 203.0.113.10:8212 --password adminpw
gamequery --batch '[{"protocol":"source","address":"1.2.3.4:27015","id":"a"}]'
```

Emits JSON on stdout; always exits 0 (check the `online` field) unless there's a
usage error.

## Protocols

`source` (+ `-players`/`-full`), `minecraft`, `bedrock`, `fivem`, `palworld`,
`quake2`, `quake3`, `gamespy1`, `gamespy2`, `gamespy3`, `unreal2`, `doom3`,
`ase`, `mumble`, `frostbite`, `assettocorsa`, `teamspeak3`, `terraria`, `samp`
(alias `openmp`), `quakeworld` (alias `quake1`), `minecraft-legacy`. See the
[main README](../README.md) for the games each covers.

Protocols that embed the server's address in their payload (SA-MP/open.mp)
override `requiresAddressResolution()`; the transport resolves the host to an
IPv4 address before `initialStep()` and exposes it via `server.address()`.

## Develop

```bash
npm install
npm run build
npm test
```
