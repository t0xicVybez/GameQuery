# Changelog

All notable changes to GameQuery are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/), and the project aims to follow
[Semantic Versioning](https://semver.org/). The PHP (`t0xicvybez/gamequery`) and
Node (`@t0xicvybez/gamequery`) ports share this changelog and version.

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

[0.3.0]: https://github.com/t0xicVybez/GameQuery/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/t0xicVybez/GameQuery/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/t0xicVybez/GameQuery/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/t0xicVybez/GameQuery/releases/tag/v0.1.0
