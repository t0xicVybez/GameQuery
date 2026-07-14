# Contributing to GameQuery

Thanks for your interest. GameQuery is a dependency-free game-server query
library that ships **two ports that stay in lockstep**: PHP (`src/`) and
Node/TypeScript (`node/src/`). The single most important rule here:

> **Every protocol and public API change must land in both ports, with matching
> tests, in the same PR.** The two implementations are deliberately mirror
> images — same protocol names, same conversation model, same result fields.

## Project layout

```
src/                     PHP library (PSR-4 "GameQuery\")
  Protocol/              one class per protocol
  Transport/             SocketManager (stream_select loop) + QuerySession
  Buffer/                ByteReader / ByteWriter
tests/smoke_test.php     PHP unit suite (crafted packets, no network)
tests/integration.php    live check against real servers (network, not CI)
bin/gamequery            PHP CLI (JSON out)

node/src/                TypeScript port (ESM, mirrors src/ one-to-one)
  protocol/ transport/ buffer/
  tests/smoke.test.ts       TS unit suite
  tests/integration.test.ts live check
  bin/gamequery.ts          CLI
```

The protocol files map 1:1: `src/Protocol/Foo.php` ⇔ `node/src/protocol/Foo.ts`.

## Running the tests

```bash
# PHP
php tests/smoke_test.php        # unit suite — must be all-green
php tests/integration.php       # live (edit the target list first)

# Node
cd node
npm install                     # dev deps only: typescript + @types/node
npm test                        # tsc build + unit suite
npm run test:integration        # live
```

Both unit suites are pure (crafted byte packets, no sockets), so they run
anywhere and gate every change. The integration scripts hit real servers and
are diagnostic only — never wire them into CI.

## Adding a protocol

1. Write `src/Protocol/YourGame.php` extending `AbstractProtocol`, and the
   twin `node/src/protocol/YourGame.ts` extending its `AbstractProtocol`.
   Model on an existing pair: `Quake3` (single-shot UDP), `Minecraft`
   (length-framed TCP), `FiveM`/`Palworld` (HTTP), or `Samp` (needs the
   resolved IP — override `requiresAddressResolution()`).
2. Implement `transport()`, `initialStep()`, `nextStep()`, `parse()`, and (for
   TCP) `isResponseComplete()`. Keep protocol classes **stateless** — one
   instance is shared across all servers; per-server state lives in `history`.
3. Register the name in **both** `src/ProtocolRegistry.php` and
   `node/src/ProtocolRegistry.ts`.
4. Add matching assertions to **both** smoke suites, using a crafted response
   packet (no live server needed).
5. Update the protocol lists in `README.md` and `node/README.md`.

Result fields should stay consistent across protocols where they mean the same
thing: `name`, `map`, `players`, `max_players`, `players_list`, plus
protocol-specific extras.

## Conventions

- **No new runtime dependencies**, ever, in either port. That's the point of
  the library. Dev-only tooling (the TS compiler) is the sole exception.
- PHP targets **8.1+** (uses `readonly` properties). TS targets **Node 18+**,
  ESM, `strict` with `noUncheckedIndexedAccess`.
- Match the surrounding style; keep the two ports readable as translations of
  each other.
- Commits: short imperative subject, e.g. `feat: add <protocol> (PHP + TS)`.

## Reporting protocol quirks

Game protocols are full of version-specific edge cases. If you find a server
that parses wrong, a crafted-packet reproduction in the smoke suite is worth
ten prose descriptions — open an issue with the raw bytes if you can.
