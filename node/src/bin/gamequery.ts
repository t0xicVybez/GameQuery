#!/usr/bin/env node
/**
 * CLI wrapper — get query results as JSON from any process.
 *
 *   gamequery source 127.0.0.1:27015
 *   gamequery minecraft mc.example.com:25565
 *   gamequery palworld 203.0.113.10:8212 --password adminpw
 *   gamequery --batch '[{"protocol":"source","address":"1.2.3.4:27015","id":"a"}]'
 *
 * Always exits 0 with JSON on stdout, even for offline servers — check the
 * `online` field. A non-zero exit means a usage error, not a server being down.
 */
import { GameQuery } from '../GameQuery.js';

function fail(message: string): never {
  process.stderr.write(message + '\n');
  process.exit(1);
}

async function main(): Promise<void> {
  const args = process.argv.slice(2);
  if (args.length === 0) {
    fail("Usage: gamequery <protocol> <host:port> [--password X] | gamequery --batch '<json>'");
  }

  const gq = new GameQuery();
  const isBatch = args[0] === '--batch';

  if (isBatch) {
    const json = args[1] ?? fail('--batch requires a JSON argument');
    let entries: unknown;
    try {
      entries = JSON.parse(json);
    } catch {
      fail('--batch argument must be valid JSON');
    }
    if (!Array.isArray(entries)) fail('--batch argument must be a JSON array');
    for (const entry of entries as Array<Record<string, unknown>>) {
      if (typeof entry.protocol !== 'string' || typeof entry.address !== 'string') {
        fail('Each batch entry needs "protocol" and "address"');
      }
      gq.addServer(
        entry.protocol,
        entry.address,
        entry.id ?? null,
        (entry.options as Record<string, unknown>) ?? {},
      );
    }
  } else {
    const protocol = args[0] as string;
    const address = args[1] ?? fail('Missing host:port argument');
    const options: Record<string, unknown> = {};
    for (let i = 2; i < args.length; i += 2) {
      const flag = args[i] as string;
      if (!flag.startsWith('--')) fail(`Unexpected argument '${flag}', expected a --flag`);
      const value = args[i + 1] ?? fail(`Flag ${flag} needs a value`);
      options[flag.slice(2)] = value;
    }
    gq.addServer(protocol, address, null, options);
  }

  const results = (await gq.process()).map((r) => r.toObject());
  process.stdout.write(JSON.stringify(isBatch ? results : results[0], null, 2) + '\n');
}

main().catch((err) => fail(err instanceof Error ? err.message : String(err)));
