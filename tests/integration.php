<?php

declare(strict_types=1);

/**
 * Live integration check — NOT part of the unit suite.
 *
 * This actually goes out over the network and queries real, public game
 * servers, so results depend on those servers being up and reachable from
 * wherever you run it. It never fails the process on an offline server (a
 * down server is data, not a bug); it prints a table and always exits 0.
 * Use it to sanity-check a protocol end to end against something real.
 *
 *   php tests/integration.php
 *
 * The default list below sticks to endpoints that are reliably online. Add
 * your own targets — one per protocol you want to smoke-test live — in the
 * form [protocol, host:port, label, options].
 */

require __DIR__ . '/../autoload.php';

use GameQuery\GameQuery;

/** @var list<array{0:string,1:string,2:string,3?:array<string,mixed>}> $targets */
$targets = [
    ['minecraft', 'mc.hypixel.net:25565', 'Hypixel (Java)'],
    ['minecraft', 'play.cubecraft.net:25565', 'CubeCraft (Java)'],
    ['bedrock', 'geo.hivebedrock.network:19132', 'The Hive (Bedrock)'],
    ['bedrock', 'play.cubecraft.net:19132', 'CubeCraft (Bedrock)'],

    // --- Add your own below (these are placeholders; edit or delete) ---------
    // ['source', '1.2.3.4:27015', 'My CS2 server'],
    // ['fivem', '1.2.3.4:30120', 'My FiveM server'],
    // ['palworld', '1.2.3.4:8212', 'My Palworld', ['password' => 'adminpw']],
    // ['samp', '1.2.3.4:7777', 'My SA-MP server'],
    // ['teamspeak3', '1.2.3.4:10011', 'My TS3 (query port must be open)', ['voicePort' => 9987]],
];

$gq = new GameQuery(timeoutMs: 3000, retries: 1);
foreach ($targets as $t) {
    $gq->addServer($t[0], $t[1], $t[2], $t[3] ?? []);
}

$start = microtime(true);
$results = $gq->process();
$elapsed = (microtime(true) - $start) * 1000;

printf("%-22s %-9s %-6s %8s  %-30s %s\n", 'label', 'protocol', 'up', 'ping', 'name', 'players');
printf("%s\n", str_repeat('-', 100));

$online = 0;
foreach ($results as $r) {
    if ($r->online) {
        $online++;
    }
    $name = (string) ($r->data['name'] ?? '');
    $name = strlen($name) > 30 ? substr($name, 0, 27) . '...' : $name;
    $players = isset($r->data['players'])
        ? sprintf('%s/%s', $r->data['players'], $r->data['max_players'] ?? '?')
        : ($r->error ?? '-');

    printf(
        "%-22s %-9s %-6s %7.1fms  %-30s %s\n",
        substr((string) $r->server->id, 0, 22),
        $r->server->protocol,
        $r->online ? 'yes' : 'no',
        $r->pingMs,
        preg_replace('/[[:cntrl:]]/', '', $name) ?? '',
        $players
    );
}

printf("\n%d/%d online — queried concurrently in %.0fms total.\n", $online, count($results), $elapsed);
exit(0);
