# GameQuery

A dependency-free PHP library for querying multiplayer game server status
— player counts, map, hostname, ping, rules/cvars — from one or many
servers concurrently.

Built by Cory ([@t0xicVybez](https://github.com/t0xicVybez)). No external
libraries required; runs on plain PHP 8+ with core streams, so it drops
into a shared PHP webhost as easily as it runs standalone on a VPS next to
[ArkenBot](https://github.com/t0xicVybez/ArkenBot).

## Why this exists

Most PHP game-query libraries (GameQ included) do the same core job: send a
UDP/TCP packet in a game's native query protocol, parse the binary reply.
This is a from-scratch implementation of that idea — its own architecture,
its own protocol-conversation model, its own concurrency engine — not a
port or adaptation of any existing library's code.

## Features

- **True concurrency, no dependencies.** One `stream_select()` event loop
  drives every server's query state machine at once. Querying 25 servers
  takes about as long as the single slowest one, not the sum of all of them
  (verified: 20 responsive + 5 unresponsive servers, both finishing in
  ~2s — bounded by the timeout, not the count).
- **Protocols implemented:**
  - **Source / A2S** (`source`) — every Source-engine game (CS2, CS:GO,
    TF2, Garry's Mod, ...) plus most Steamworks-registered dedicated
    servers, since A2S is a generic Steamworks feature, not a Source-only
    one. Covers Space Engineers, Rust, ARK, and SCUM in practice.
    - `source` — server info only (name, map, player count, etc.)
    - `source-players` — info + per-player name/score/time list
    - `source-full` — info + players + rules/cvars
  - **Minecraft: Java Edition** (`minecraft`) — modern Server List Ping
    (name, version, player count, sample player list, favicon presence).
  - **Minecraft: Bedrock Edition** (`bedrock`, alias `minecraft-bedrock`) —
    RakNet Unconnected Ping/Pong over UDP (name, versions, player count,
    world/MOTD line, gamemode). Default port 19132.
  - **FiveM / CFX** (`fivem`) — GTA V multiplayer. Speaks the server's HTTP
    JSON endpoints (`/info.json` + `/players.json`) rather than a binary
    protocol. Default port 30120.
    - `fivem` — server info + connected player list
    - `fivem-info` — server info only (skips the players call)
  - **Palworld** (`palworld`) — its dedicated server has no binary query
    protocol; it exposes a REST API instead, so this speaks HTTP + Basic
    Auth rather than raw packets. Needs the server's admin password (see
    Usage below).
    - `palworld` — server info + connected player list
    - `palworld-info` — server info only (no auth-protected players call)
- **Works standalone or via Composer.** `autoload.php` for a plain FTP
  drop-in on a shared host; `composer.json` if you'd rather pull it in as
  a package.
- **CLI + JSON**, for calling from a non-PHP process (`bin/gamequery`) —
  built specifically so ArkenBot's Node/TypeScript side can shell out to
  it instead of needing a PHP runtime embedded in the bot.

## Installation

**Composer:**
```bash
composer require t0xicvybez/gamequery
```

**Standalone (no Composer, e.g. a shared webhost):** upload the `src/`
folder and `autoload.php`, then:
```php
require __DIR__ . '/autoload.php';
```

## Usage

```php
use GameQuery\GameQuery;

$gq = new GameQuery(timeoutSeconds: 2.0, retries: 1);

$gq->addServer('source', '127.0.0.1:27015', id: 'my-css-server');
$gq->addServer('source-players', '203.0.113.10:27015', id: 'ranked-server');
$gq->addServer('minecraft', 'mc.example.com:25565', id: 'survival');

// Protocols that need credentials take them in a per-server options bag
// (never baked into the protocol class -- one Palworld protocol instance
// is shared across every Palworld server you query, which can each have
// a different admin password).
$gq->addServer('palworld', '203.0.113.10:8212', id: 'my-pal-server', options: [
    'password' => 'the-admin-password', // required
    // 'username' => 'admin',           // optional, this is already the default
]);

foreach ($gq->process() as $result) {
    if (!$result->online) {
        echo "{$result->server->label()} is offline ({$result->error})\n";
        continue;
    }

    echo "{$result->data['name']}: {$result->data['players']}/{$result->data['max_players']} players, {$result->pingMs}ms\n";
}
```

Every `Result` is returned in add order and always has a value — offline
servers come back with `online: false` and an `error` string, never an
exception. `$result->data` holds whatever the protocol parsed (see each
protocol class's `parse()` for the exact field list).

### From Node / ArkenBot

```js
const { execFile } = require('child_process');

execFile('php', ['/path/to/GameQuery/bin/gamequery', 'source', '127.0.0.1:27015'], (err, stdout) => {
  const result = JSON.parse(stdout);
  console.log(result.online, result.players, result.max_players);
});
```

Palworld needs its admin password passed as a flag (or via `--batch`, see below):
```js
execFile('php', ['bin/gamequery', 'palworld', '203.0.113.10:8212', '--password', adminPw], (err, stdout) => {
  const result = JSON.parse(stdout);
});
```

Or batch multiple servers, mixing protocols and per-server options, in one call:
```js
const servers = JSON.stringify([
  { protocol: 'source', address: '127.0.0.1:27015', id: 'css' },
  { protocol: 'minecraft', address: 'mc.example.com:25565', id: 'mc' },
  { protocol: 'palworld', address: '203.0.113.10:8212', id: 'pal', options: { password: adminPw } },
]);
execFile('php', ['bin/gamequery', '--batch', servers], (err, stdout) => {
  const results = JSON.parse(stdout); // array
});
```

The `--batch` form is the better choice once a password is involved: CLI
flags like `--password` are visible to anything that can run `ps` on the
box, while `--batch`'s JSON argument doesn't show up any more than the
rest of the argument does.

## Architecture

```
GameQuery              facade: addServer() / process()
  ProtocolRegistry      name string -> ProtocolInterface instance
  Server / Result       immutable value objects in, value objects out
  Transport/
    SocketManager        the concurrent event loop (stream_select)
    QuerySession          one server's state machine, driven by the loop
  Protocol/
    ProtocolInterface     the contract every game protocol implements
    AbstractProtocol      shared bookkeeping (tag lookup, UDP defaults)
    Source                A2S implementation
    Minecraft              Java Edition SLP implementation
    Palworld                REST API + Basic Auth implementation
  Buffer/
    ByteReader / ByteWriter   binary (de)serialization helpers
```

**The core idea:** every game query protocol, no matter how different its
byte layout, is a *linear conversation* — send a packet, look at the
reply, decide whether to send another one. A protocol implementation is
just three methods (`initialStep`, `nextStep`, `parse`) describing that
conversation; it never touches a socket. `SocketManager`/`QuerySession`
own every socket, timer, and retry, and know nothing about A2S or
Minecraft specifically. That split is what makes adding a new game a
matter of writing one focused class instead of touching the networking
code at all.

## Adding a new protocol

1. Create `src/Protocol/YourGame.php` extending `AbstractProtocol`.
2. Implement `transport()` (`'udp'` or `'tcp'`), `initialStep()`,
   `nextStep()`, and `parse()`. Look at `Source.php` for a
   challenge/response example or `Minecraft.php` for a single-shot one.
3. Register it — either permanently in `ProtocolRegistry`'s constructor,
   or at runtime:
   ```php
   $gq->registerProtocol('yourgame', fn() => new \GameQuery\Protocol\YourGame());
   $gq->addServer('yourgame', 'host:port');
   ```

No changes to `SocketManager` or `QuerySession` are ever needed for a new
protocol — that's the whole point of the split.

## Known limitations (honest, not marketing copy)

- **A2S multi-packet / compressed responses aren't reassembled.** A
  server with an unusually large `A2S_RULES` reply that gets fragmented
  across multiple UDP datagrams, or that returns bzip2-compressed data,
  won't parse correctly. `A2S_INFO` and `A2S_PLAYER` fit in one datagram
  for essentially all real servers; `A2S_RULES` is the one that can
  theoretically fragment on servers with a huge cvar list.
- **Minecraft Bedrock Edition isn't supported.** Bedrock uses an entirely
  different UDP/RakNet-based protocol; `minecraft` here is Java Edition
  only.
- **No dedicated Minecraft ping/pong latency packet.** Latency is measured
  as connect + status round-trip time rather than the protocol's
  purpose-built ping packet (0x01). Close enough for "is it up," not
  meant for serious latency graphing.
- **"The Ship"'s extra 3 A2S_INFO bytes aren't parsed** (a Source game
  with a nonstandard info payload). Everything else in that response still
  parses correctly; those specific 3 bytes are simply not extracted.
- **Only two protocols ship today.** The architecture is built to make
  adding more a small, isolated task, but Source/A2S and Minecraft Java
  are what's implemented right now.
- **Palworld assumes the server keeps the HTTP connection alive** between
  its info and players requests (Go's `net/http`, which Palworld's REST
  API is built on, defaults to this). If a given server's setup somehow
  closes the connection after one request, the players step silently
  fails and you get info-only data back rather than an error — verified
  against a fake server that intentionally didn't keep the connection
  alive, and the library degrades gracefully rather than crashing, but it
  won't retry over a fresh connection today. `palworld-info` sidesteps
  this entirely if you only need server info.
- **Palworld's chunked transfer-encoding isn't handled**, only
  `Content-Length`-based responses. Its REST API is a small, mostly-static
  JSON API, so this is unlikely to matter in practice.
- **No Palworld write actions.** Only the read-only `GET /v1/api/info`
  and `GET /v1/api/players` endpoints are wired up. Kick/ban/shutdown/
  broadcast (`POST` endpoints) are intentionally out of scope for a
  status-query library.

## Testing

```bash
php tests/smoke_test.php
```

Dependency-free assertions against hand-built, known-good protocol byte
sequences (no live server needed) — binary buffer round-tripping, A2S
packet parsing, the challenge-number hand-off between query steps,
Minecraft's length-prefixed JSON framing, and Palworld's HTTP request
building / auth-error handling / missing-credential safety. All 30 checks
pass as of this writing. The library has also been exercised end-to-end
against a local fake UDP A2S server (full challenge/response player query
flow), a fake Palworld REST server (both the keep-alive happy path and a
non-keep-alive one to confirm graceful degradation, plus wrong-password
and missing-password paths), a "black hole" UDP endpoint to confirm
timeout/retry timing lands where expected, and a batch of 20 responsive +
5 unresponsive servers to confirm the whole batch completes in one
timeout window rather than scaling with server count.

## License

MIT — see composer.json.
