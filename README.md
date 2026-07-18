# GameQuery

[![Packagist Version](https://img.shields.io/packagist/v/t0xicvybez/gamequery?label=packagist)](https://packagist.org/packages/t0xicvybez/gamequery)
[![npm version](https://img.shields.io/npm/v/@t0xicvybez/gamequery?label=npm)](https://www.npmjs.com/package/@t0xicvybez/gamequery)
[![PHP 8.1+](https://img.shields.io/badge/php-8.1%2B-777bb4)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

A dependency-free game server query library — read player counts, map, hostname,
ping, and rules from one or many servers at once. It ships as **two parallel
ports that stay in lockstep**: a **PHP** library (`t0xicvybez/gamequery`) and a
**Node/TypeScript** library (`@t0xicvybez/gamequery`), with the same protocols,
the same API, and the same results.

- **Zero runtime dependencies.** PHP runs on core streams (8.1+); Node runs on
  built-in `dgram`/`net` (18+). Nothing to `require`, nothing to audit.
- **Concurrent by design.** One event loop drives every server's query at once,
  so polling 25 servers takes about as long as the single slowest one — not the
  sum of all of them.
- **21 protocol families / 30 registered keys**, covering A2S/Source, both
  Minecraft editions, FiveM, Palworld, the GameSpy and id Tech families, voice
  servers, and more (full table below).
- **Never throws for an unreachable server.** Every query returns a `Result`;
  offline servers come back with `online = false` and an `error` string.

Built by [@t0xicVybez](https://github.com/t0xicVybez) and used in production by
[ArkenBot](https://github.com/t0xicVybez/ArkenBot).

## Installation

**PHP (Composer):**
```bash
composer require t0xicvybez/gamequery
```

**PHP (standalone, e.g. a shared webhost with no Composer):** drop in the `src/`
folder and `autoload.php`, then `require __DIR__ . '/autoload.php';`.

**Node / TypeScript:**
```bash
npm install @t0xicvybez/gamequery
```

See [`node/`](node/) for the TypeScript API and CLI details.

## Quick start

**PHP:**
```php
use GameQuery\GameQuery;

$gq = new GameQuery(timeoutMs: 2000, retries: 1);

$gq->addServer('source', '127.0.0.1:27015', id: 'my-css-server');
$gq->addServer('minecraft', 'mc.example.com:25565', id: 'survival');

// Protocols that need credentials take a per-server options bag — the same
// protocol instance serves every server, so nothing is baked into the class.
$gq->addServer('palworld', '203.0.113.10:8212', id: 'pal', options: [
    'password' => 'the-admin-password',
]);

foreach ($gq->process() as $result) {
    if (!$result->online) {
        // errorCode is a stable ErrorCode:: constant (TIMEOUT, UNREACHABLE, ...)
        echo "{$result->server->label()} is offline [{$result->errorCode}]\n";
        continue;
    }
    // Normalized accessors read the right field for any protocol.
    echo "{$result->name()}: {$result->players()}/{$result->maxPlayers()} players, {$result->pingMs}ms\n";
}
```

Just one server? Skip the ceremony:
```php
$r = GameQuery::queryOne('source', '127.0.0.1:27015');
```

**Node / TypeScript:**
```ts
import { GameQuery } from '@t0xicvybez/gamequery';

const gq = new GameQuery(/* timeoutMs */ 2000, /* retries */ 1);
gq.addServer('source', '127.0.0.1:27015', 'my-css-server');
gq.addServer('minecraft', 'mc.example.com:25565');

for (const r of await gq.process()) {
  if (!r.online) {
    console.log(`${r.server.label()} is offline [${r.errorCode}]`);
    continue;
  }
  console.log(`${r.name()}: ${r.players()}/${r.maxPlayers()}, ${r.pingMs}ms`);
}

// Or a single server:
const one = await GameQuery.queryOne('source', '127.0.0.1:27015');
```

### Result API

- **Normalized accessors** — `name()`, `map()`, `players()`, `maxPlayers()`,
  `playerNames()` — read the right field regardless of protocol. Raw
  protocol-specific fields remain on `data`.
- **`errorCode`** — a stable `ErrorCode` value on failures (`TIMEOUT`,
  `UNREACHABLE`, `CONNECTION_CLOSED`, `AUTH_FAILED`, `PROTOCOL_ERROR`,
  `CONFIG_ERROR`) — switch on it instead of matching the human `error` string.
- **`maxConcurrent`** — an optional third constructor arg caps how many sockets
  are open at once (`new GameQuery(2000, 1, 256)`); use it for large fleets.
- **`toArray()` / `toObject()`** — both serialize the result (the CLI's JSON shape).

Results come back in add order, one per server. `data` holds whatever the
protocol parsed — see each protocol class's `parse()` for its exact fields.

## Supported protocols

**21 protocol families / 30 registered keys**, identical across both ports.
Parenthesised keys are aliases or variants (e.g. `source-players` adds the
player list, `-info` variants skip it).

| Key | Family / games | Transport |
|-----|----------------|-----------|
| `source` (`source-players`, `source-full`) | Source / A2S — CS2, TF2, Rust, ARK, GMod, SCUM, most Steamworks games | UDP |
| `minecraft` | Minecraft: Java Edition (modern Server List Ping) | TCP |
| `minecraft-legacy` | Minecraft: Java Edition (≤1.6 legacy ping) | TCP |
| `bedrock` (`minecraft-bedrock`) | Minecraft: Bedrock Edition (RakNet) | UDP |
| `fivem` (`fivem-info`) | FiveM / CFX (GTA V multiplayer) | TCP/HTTP |
| `palworld` (`palworld-info`) | Palworld REST API | TCP/HTTP |
| `quakeworld` (`quake1`) | QuakeWorld / Quake 1 | UDP |
| `quake2` | id Tech 2 / Quake 2 | UDP |
| `quake3` | id Tech 3 — Quake 3, CoD 1/2/4, OpenArena, Xonotic, ET | UDP |
| `gamespy1` | GameSpy 1 — Unreal, early UT, Tribes 2, older titles | UDP |
| `gamespy2` | GameSpy 2 — Battlefield 1942/Vietnam, Halo, UT2004 | UDP |
| `gamespy3` | GameSpy 3 — Battlefield 2, Crysis, UT3, later titles | UDP |
| `unreal2` (`unreal2-info`) | Unreal Engine 2 — UT2003/2004, Killing Floor | UDP |
| `doom3` | id Tech 4 — Doom 3, Quake 4, ET: Quake Wars, Prey | UDP |
| `ase` | All-Seeing Eye — Multi Theft Auto (MTA:SA) | UDP |
| `mumble` | Mumble / Murmur voice servers | UDP |
| `teamspeak3` | TeamSpeak 3 / TeaSpeak (ServerQuery) | TCP |
| `frostbite` | Battlefield 3/4, Bad Company 2, Medal of Honor | TCP |
| `assettocorsa` | Assetto Corsa (HTTP `/INFO`) | TCP/HTTP |
| `terraria` | Terraria via TShock REST | TCP/HTTP |
| `samp` (`openmp`, `samp-info`) | SA-MP / open.mp (GTA: San Andreas) | UDP |

A few protocols need extra input: **Palworld** and **Terraria** take an admin
password / token, **TeamSpeak 3** takes the voice port, and **Assetto Corsa**
is queried on its HTTP port. Pass these through the per-server `options` bag —
each protocol class's docblock lists the keys it reads.

## Command-line interface

Both ports ship a `gamequery` CLI that emits JSON on stdout — handy for calling
from any language, or from a shell:

```bash
# PHP
php bin/gamequery source 127.0.0.1:27015
php bin/gamequery palworld 203.0.113.10:8212 --password adminpw

# Node (after: npm i -g @t0xicvybez/gamequery)
gamequery minecraft mc.example.com:25565
gamequery --batch '[{"protocol":"source","address":"1.2.3.4:27015","id":"a"}]'
```

The `--batch` form takes a JSON array and queries every entry concurrently. It's
also the right choice when a password is involved: a `--password` flag is visible
to anything that can run `ps` on the box, whereas the batch JSON is no more
exposed than any other argument. The CLI always exits `0` (check the `online`
field) unless there's a usage error.

## Architecture

```
GameQuery                facade: addServer() / process()
  ProtocolRegistry       name string -> protocol instance (30 keys)
  Server / Result        immutable value objects in, value objects out
  Transport/
    SocketManager        the concurrent event loop
    QuerySession         one server's state machine, driven by the loop
  Protocol/
    ProtocolInterface    the contract every game protocol implements
    AbstractProtocol     shared bookkeeping (tag lookup, defaults)
    Source, Minecraft, Palworld, … (21 protocol classes)
  Buffer/
    ByteReader / ByteWriter   binary (de)serialization helpers
```

**The core idea:** every game query protocol, however different its byte layout,
is a *linear conversation* — send a packet, look at the reply, decide whether to
send another. A protocol is just three methods (`initialStep`, `nextStep`,
`parse`) describing that conversation; it never touches a socket. The transport
layer owns every socket, timer, and retry and knows nothing about A2S or
Minecraft specifically. That split is what keeps the concurrency engine small and
makes adding a game a matter of writing one focused class.

## Adding a protocol

1. Create the protocol class in **both** ports (`src/Protocol/YourGame.php` and
   `node/src/protocol/YourGame.ts`), extending `AbstractProtocol`.
2. Implement `transport()`, `initialStep()`, `nextStep()`, and `parse()`. See
   `Source` for a challenge/response example or `Minecraft` for a single-shot one.
3. Register it in both `ProtocolRegistry` files, or at runtime:
   ```php
   $gq->registerProtocol('yourgame', fn() => new \GameQuery\Protocol\YourGame());
   ```
4. Add a crafted-packet test to both smoke suites.

No changes to the transport layer are ever needed for a new protocol. The PHP and
Node ports must move together — see [CONTRIBUTING.md](CONTRIBUTING.md).

**Address resolution:** protocols whose request embeds the server's own numeric
IP (SA-MP/open.mp) override `requiresAddressResolution()` to return `true`; the
transport then resolves the host to an IPv4 address before `initialStep()` and
exposes it via `Server::address()`. Protocols that don't opt in pay no cost.

## Known limitations

Kept deliberately honest — these are the edges worth knowing about:

- **A2S multi-packet / compressed replies aren't reassembled.** A `source` query
  whose response is split across several UDP datagrams (or bzip2-compressed) —
  which in practice only happens to `A2S_RULES` on servers with a very large cvar
  list — won't parse fully. `A2S_INFO` and `A2S_PLAYER` fit in one datagram for
  essentially all real servers.
- **Minecraft latency is round-trip-based.** `pingMs` for Minecraft is the
  connect + status round trip rather than the protocol's dedicated ping/pong
  packet. Accurate enough for "is it up and how far away," not for fine-grained
  latency graphing.
- **Palworld reads only, over `Content-Length` responses.** The read-only
  `info`/`players` GET endpoints are implemented; the mutating admin actions
  (kick/ban/shutdown) are intentionally out of scope. Response completion relies
  on `Content-Length` (which Palworld's Go-based REST API always sends) and both
  requests share one keep-alive connection — a server configured to close after
  each request would yield info-only data rather than an error. Use
  `palworld-info` if you only need server info.

## Testing

```bash
php tests/smoke_test.php          # PHP unit suite (offline)
cd node && npm test               # TS unit suite (build + run, offline)

php tests/integration.php                 # optional live checks
cd node && npm run test:integration       # (edit the server list first)
```

The unit suites are dependency-free assertions against hand-built, known-good
protocol byte sequences — buffer round-tripping, A2S parsing (including the 2020
`A2S_INFO` challenge and the "Ship" info variant), challenge hand-offs, Minecraft
JSON framing, Palworld auth, and a crafted-packet case for every protocol. No
network needed; both suites must stay all-green, and every protocol change lands
in both ports with matching tests.

The `integration.php` / `integration.test.ts` scripts are separate,
network-dependent diagnostics: they query real public servers and print a table,
never failing the process on an offline server. Point them at your own servers to
smoke-test a protocol end to end.

## License

[MIT](LICENSE) © [@t0xicVybez](https://github.com/t0xicVybez)
