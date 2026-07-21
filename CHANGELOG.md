# Changelog

All notable changes to GameQuery are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/), and the project aims to follow
[Semantic Versioning](https://semver.org/). The PHP (`t0xicvybez/gamequery`) and
Node (`@t0xicvybez/gamequery`) ports share this changelog and version.

## [0.5.3] - 2026-07-21

### Added
- **`gamePort` in the game database** — the port players *join* on, for the games
  whose join port differs from the port GameQuery queries. Killing Floor 2, for
  example, answers A2S on 27015 but is joined on 7777; Terraria's TShock REST
  API is on 7878 while the game is on 7777. Set on 14 games (Valheim, DayZ,
  Conan Exiles, Killing Floor 2, Insurgency: Sandstorm, Squad, Post Scriptum,
  Arma 3, Palworld, Terraria, Assetto Corsa, UT2004, MTA:SA, TeamSpeak 3) and
  omitted wherever the two ports are the same.

  It is **advisory only and never queried** — it exists so a caller can show a
  correct "connect on `host:port`" address without pointing the query at it. The
  parity check now compares it across both ports.

## [0.5.2] - 2026-07-21

### Added
- **9 more games in the database** — Avorion, Empyrion, Ground Branch, Hurtworld,
  Miscreated, Pavlov VR, Post Scriptum, Stationeers, and Wreckfest (all A2S /
  `source`), bringing the `queryGame()` catalogue to 53.

### Changed
- **Terraria (TShock) token is now optional.** TShock's `/v2/server/status` is a
  public endpoint that only needs `RestApiEnabled = true` server-side, so the
  protocol now queries anonymously by default and appends `&token=` only when a
  token is supplied (for admins who lock the endpoint down). Previously it threw
  when no token was given. Verified live against a TShock 6.1 server both ways.

## [0.5.1] - 2026-07-20

### Fixed
- **HTTP `Transfer-Encoding: chunked` responses** are now handled by the shared
  HTTP helper (FiveM, Palworld, Terraria, Assetto Corsa). Previously, a response
  without a `Content-Length` was treated as complete the moment its headers
  arrived, so a chunked body (what live CitizenFX/FiveM servers actually send)
  was read partially and never de-chunked — the JSON failed to parse and the
  two-request conversation desynced, yielding an "online" result with an empty
  name and `0/0` players. Completion now waits for the terminating zero-length
  chunk and the body is de-chunked before parsing. Verified live against a
  FiveM server returning both endpoints chunked.

## [0.5.0] - 2026-07-20

### Added
- **Query by game.** A 44-game database maps game ids to protocol + default port,
  so you can `GameQuery::queryGame('rust', host)` / `->addGame('cs2', host)`
  without knowing it's A2S on a particular port.
- **Streaming results.** `processStream()` yields each `Result` the moment its
  server answers instead of waiting for the slowest — a `for await` async
  iterator in Node, a `Generator` (`foreach`) in PHP.
- **Dual ESM + CommonJS build (Node).** The package now ships both, wired via an
  `exports` map, so `require()` works alongside `import`. Runtime stays
  dependency-free (esbuild is dev-only).
- **`ProtocolRegistry::names()`** already listed protocols; `GAMES` / `gameInfo()`
  now expose the game database too.

### Changed
- The PHP `SocketManager` loop was refactored into a shared `drive()` generator
  behind `run()` and the new `runStream()` (no behavior change to `run()`).

### CI / release
- **Tag-triggered release workflow** publishes to npm with **build provenance**
  and cuts a GitHub Release from the CHANGELOG (needs an `NPM_TOKEN` secret).

## [0.4.0] - 2026-07-20

### Added
- **Satisfactory** (`satisfactory`) — the Lightweight Query UDP protocol
  (name/state/build). Player counts aren't exposed by that query.
- **Steam master-server discovery** — `GameQuery::listServers(filter, region)`
  returns `ip:port` strings for Source/A2S servers matching a filter, to feed
  into `addServer('source', …)`.
- **`Result::playerList()`** — structured player rows (`name`, plus `score` /
  `duration` where the protocol provides them), alongside `playerNames()`.
- **IPv6 addresses** — `fromAddress` accepts the bracket form `[::1]:27015`.
- **bzip2-compressed A2S splits** are decompressed — automatically in PHP (bz2
  extension), and in Node via an injected `Source.setBzip2Decompressor()`.
- **Parser fuzzer** (`fuzz` script, both ports, wired into CI) that throws
  128k malformed buffers at every protocol and asserts none crash.
- **`ProtocolRegistry::names()`** to enumerate registered protocols; generated
  API-reference tooling (typedoc + phpDocumentor) with a Pages workflow.

### Fixed
- **Two more UDP crash paths eliminated** — a synchronous `send()` throw (seen
  when querying an IPv6 literal on a host without IPv6, and during the connect
  window) now reports offline instead of taking down the process.
- Multi-packet buffering is capped (≤256 datagrams / 2 MB) against a flooding
  peer, and the transport traps `isResponseComplete()`/`reassemble()` throws.
- `fromAddress` now splits on the last colon in both ports (a latent PHP/Node
  parity gap on unbracketed IPv6).

## [0.3.0] - 2026-07-19

### Added
- **`minecraft-query`** protocol — the Java `enable-query` (GameSpy4/UT3) UDP
  query, which returns the *full* player list unlike SLP's truncated sample.
  Crafted-packet tests plus live verification against a vanilla server with
  `enable-query=true`.
- **`minecraft-ping`** — Minecraft with the SLP 0x01 ping/pong, reporting the
  round trip as `data.ping_ms` (a purer network latency than connect+status).
- **A2S multi-packet reassembly** — large split `A2S_RULES` replies are now
  reassembled by packet number across datagrams (order-independent). Verified
  against a live server returning 120 cvars. bzip2-compressed legacy splits are
  detected and skipped (no bundled bzip2).
- **`GameQuery::queryWithPortProbe()`** — try a base port plus offsets and return
  the first that answers, for Source games whose query port is offset from the
  game port.

### Fixed
- **Node: a UDP query could crash the process** — after the 0.2.0 connected-dgram
  change, a retry/timeout during the connect window called `send()` on the
  not-yet-connected socket, throwing synchronously. Guarded.
- **Ping-timing parity** — the PHP port started the ping clock at socket open
  (including TCP connect time) while Node started it at first send; PHP now
  starts it at first send too, so `pingMs` means the same thing in both ports.

## [0.2.0] - 2026-07-18

### Added
- **Typed error codes.** `Result.errorCode` is one of a stable `ErrorCode` set
  (`TIMEOUT`, `UNREACHABLE`, `CONNECTION_CLOSED`, `AUTH_FAILED`, `PROTOCOL_ERROR`,
  `CONFIG_ERROR`) — callers no longer have to string-match the human message.
- **Normalized `Result` accessors** — `name()`, `map()`, `players()`,
  `maxPlayers()`, `playerNames()` — that read the right field regardless of
  protocol and are stable across releases.
- **`GameQuery::queryOne()`** single-shot helper for the common one-server case.
- **`maxConcurrent`** constructor option to cap how many sockets are open at once
  (0 = unlimited), for safely polling large fleets.
- **"The Ship" (app_id 2400)** A2S_INFO parsing — the extra mode/witnesses/
  duration bytes are now consumed and exposed instead of misaligning the version.
- **Tooling:** GitHub Actions CI (PHP 8.1–8.4, Node 18/20/22), PHPStan (level 5),
  ESLint + Prettier, php-cs-fixer, and a PHP↔Node parity-drift check.

### Changed
- **Timeout unit unified to milliseconds** across both ports (the PHP constructor
  previously took seconds).
- `Result` gained `toArray()`/`toObject()` on both ports (serializer parity).

### Fixed
- **PHP: a protocol `parse()` exception could crash the whole batch** — it's now
  trapped per-server and reported as `PROTOCOL_ERROR` (matching the Node port).
- **Node: UDP sockets now `connect()` to the target**, so the kernel filters to
  the queried peer — a stray or spoofed sender can no longer be read as the reply
  (matches the PHP port's connected `udp://` socket).
- **`Server::label()` parity** — the PHP port now uses the caller `id` when set,
  like the Node port.

## [0.1.1] - 2026-07-18

### Fixed
- Node: dropped the leading `./` from the `bin` path so npm keeps the `gamequery`
  CLI when installed (0.1.0 shipped without it).

## [0.1.0] - 2026-07-18

### Added
- Initial public release: dependency-free game-server query library in parallel
  PHP and Node/TypeScript ports, 21 protocol families / 30 registered keys
  (A2S/Source, Minecraft Java/legacy/Bedrock, FiveM, Palworld, the GameSpy and
  id Tech families, Mumble, TeamSpeak 3, Frostbite, Assetto Corsa, Terraria,
  SA-MP/open.mp), concurrent multi-server polling, and a JSON CLI.

[0.5.3]: https://github.com/t0xicVybez/GameQuery/compare/v0.5.2...v0.5.3
[0.5.2]: https://github.com/t0xicVybez/GameQuery/compare/v0.5.1...v0.5.2
[0.5.1]: https://github.com/t0xicVybez/GameQuery/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/t0xicVybez/GameQuery/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/t0xicVybez/GameQuery/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/t0xicVybez/GameQuery/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/t0xicVybez/GameQuery/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/t0xicVybez/GameQuery/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/t0xicVybez/GameQuery/releases/tag/v0.1.0
