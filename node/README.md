# GameQuery (Node / TypeScript)

The Node/TypeScript port of [GameQuery](https://github.com/t0xicVybez/GameQuery) —
a dependency-free game server query library. Read player counts, map, hostname,
ping and rules from one server or hundreds at once.

Same architecture, protocols and results as the PHP port
(`t0xicvybez/gamequery`), built on Node's async `dgram`/`net` sockets so
concurrency comes for free and it embeds natively in a Node app with no
subprocess.

**[query.arkenbot.app](https://query.arkenbot.app)** — docs, the full game list,
and a live demo you can point at your own server.

- **Zero runtime dependencies**, Node 18+, ships **ESM and CommonJS**.
- **23 protocol families / 33 registered keys** and a **53-game database**.
- **Never throws for an unreachable server** — every query returns a `Result`.
- Published to npm with **build provenance**.

## Install

```bash
npm install @t0xicvybez/gamequery
```

## Usage

```ts
import { GameQuery } from '@t0xicvybez/gamequery';

// One server, one line.
const r = await GameQuery.queryOne('source', '127.0.0.1:27015');
console.log(r.online, r.name(), r.players(), r.maxPlayers(), r.pingMs);

// Or by game id — protocol and default port are resolved for you.
const rust = await GameQuery.queryGame('rust', 'my-server.com');

// A whole fleet, queried concurrently.
const gq = new GameQuery(/* timeoutMs */ 2000, /* retries */ 1);
gq.addServer('source', '127.0.0.1:27015', 'my-css-server');
gq.addGame('minecraft', 'mc.example.com');
gq.addServer('palworld', '203.0.113.10:8212', 'pal', { password: 'admin-pw' });

for (const result of await gq.process()) {
  if (!result.online) {
    console.log(`${result.server.label()} is offline (${result.errorCode})`);
    continue;
  }
  console.log(`${result.name()}: ${result.players()}/${result.maxPlayers()}, ${result.pingMs}ms`);
}
```

`process()` queries every server concurrently and resolves one `Result` per
server, in add order — online or not. Polling 25 servers takes about as long as
the single slowest one.

> **Timeout unit:** the constructor takes **milliseconds** (`2000` = 2s). The PHP
> port takes the same unit, so an explicit value means the same thing in both.

### Reading results

`Result` exposes normalized accessors that read the right field regardless of
protocol and stay stable across releases:

```ts
r.online;        // boolean — never throws for an unreachable server
r.errorCode;     // TIMEOUT | UNREACHABLE | CONNECTION_CLOSED | AUTH_FAILED | PROTOCOL_ERROR | CONFIG_ERROR
r.name();        // hostname
r.map();         // current map
r.players();     // current player count
r.maxPlayers();  // slots
r.playerNames(); // string[]
r.playerList();  // structured rows (name, plus score/duration where available)
r.pingMs;        // round trip
r.data;          // the raw protocol-specific payload
r.toObject();    // serializable (the CLI's JSON shape)
```

### Other helpers

- **`queryGame()` / `addGame()`** — query by game id instead of protocol, using a
  53-game database. `gameInfo(id)` / `GAMES` expose it directly.
- **`gameInfo(id).gamePort`** — the port players *join* on, for the games where it
  differs from the port that answers queries (Killing Floor 2 is queried on
  `27015` but joined on `7777`). Advisory only — never queried.
- **`processStream()`** — async iterator yielding each `Result` the moment its
  server answers, instead of waiting for the slowest.
- **`queryWithPortProbe()`** — try a base port plus offsets, return the first that
  answers.
- **`listServers()`** — discover Source/A2S servers via the Steam master server.
- **`maxConcurrent`** — optional third constructor arg caps how many sockets are
  open at once, for large fleets.

Addresses accept IPv6 in bracket form (`[::1]:27015`). Every parser is fuzzed
against malformed input in CI, so a hostile or broken reply can't crash it.

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

All 33 registered keys:

| Key | Covers |
|-----|--------|
| `source`, `source-players`, `source-full` | Source / A2S — CS2, TF2, Rust, ARK, GMod, SCUM, most Steamworks games |
| `minecraft`, `minecraft-ping` | Minecraft: Java Edition (SLP; `-ping` adds 0x01 latency) |
| `minecraft-legacy` | Minecraft: Java Edition, ≤1.6 legacy ping |
| `minecraft-query` | Minecraft: Java `enable-query` (full player list) |
| `bedrock`, `minecraft-bedrock` | Minecraft: Bedrock Edition (RakNet) |
| `fivem`, `fivem-info` | FiveM / CFX (GTA V) |
| `palworld`, `palworld-info` | Palworld REST API (needs `password`) |
| `terraria` | Terraria via TShock REST |
| `satisfactory` | Satisfactory Lightweight Query |
| `assettocorsa` | Assetto Corsa (HTTP `/INFO`) |
| `quakeworld`, `quake1` | QuakeWorld / Quake 1 |
| `quake2` | id Tech 2 |
| `quake3` | id Tech 3 — Quake 3, CoD 1/2/4, OpenArena, Xonotic |
| `gamespy1`, `gamespy2`, `gamespy3` | GameSpy 1/2/3 families |
| `unreal2`, `unreal2-info` | Unreal Engine 2 — UT2003/2004, Killing Floor |
| `doom3` | id Tech 4 — Doom 3, Quake 4, ETQW |
| `ase` | All-Seeing Eye — Multi Theft Auto |
| `samp`, `openmp`, `samp-info` | SA-MP / open.mp |
| `frostbite` | Battlefield 3/4, Bad Company 2 |
| `mumble` | Mumble / Murmur |
| `teamspeak3` | TeamSpeak 3 / TeaSpeak (ServerQuery) |

See the [main README](https://github.com/t0xicVybez/GameQuery#protocols) for the
games each one covers, and [query.arkenbot.app](https://query.arkenbot.app) for
the searchable game database.

Protocols that embed the server's address in their payload (SA-MP/open.mp)
override `requiresAddressResolution()`; the transport resolves the host to an
IPv4 address before `initialStep()` and exposes it via `server.address()`.

## Develop

```bash
npm install
npm run build      # ESM
npm run build:all  # ESM + CJS bundle
npm test
npm run fuzz
```

## Licence

[AGPL-3.0-or-later](https://github.com/t0xicVybez/GameQuery/blob/main/LICENSE).
Use it, modify it and contribute freely; if you distribute a modified version —
**including running one as a network service** — you must make your source
available under the same licence.

**Every published release is AGPL** — the pre-0.5.4 releases, which were MIT, have
been withdrawn, so the AGPL is the only licence GameQuery is distributed under.

Issues and source:
[github.com/t0xicVybez/GameQuery](https://github.com/t0xicVybez/GameQuery).
