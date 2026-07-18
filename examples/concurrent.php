<?php

require __DIR__ . '/../vendor/autoload.php';

use GameQuery\GameQuery;

// A mix of live and deliberately-dead servers to show that one slow/dead
// entry doesn't stall the rest of the batch.
$servers = [
    ['source', '127.0.0.1:27015', 'local-css'],
    ['minecraft', 'mc.hypixel.net:25565', 'hypixel'],
    ['source', '192.0.2.1:27015', 'unreachable-example'], // TEST-NET-1, always unreachable
];

$gq = new GameQuery(timeoutMs: 1500, retries: 1);

foreach ($servers as [$protocol, $address, $id]) {
    $gq->addServer($protocol, $address, $id);
}

$start = microtime(true);
$results = $gq->process();
$elapsed = (microtime(true) - $start) * 1000;

foreach ($results as $result) {
    printf(
        "%-24s online=%-5s ping=%6.1fms error=%s\n",
        $result->server->id,
        $result->online ? 'true' : 'false',
        $result->pingMs,
        $result->error ?? '-'
    );
}

printf("\nQueried %d servers in %.1fms total (concurrently, not sequentially)\n", count($servers), $elapsed);
