<?php

require __DIR__ . '/../vendor/autoload.php';
// Standalone (no composer)? Use this instead:
// require __DIR__ . '/../autoload.php';

use GameQuery\GameQuery;

$gq = new GameQuery(timeoutSeconds: 2.0, retries: 1);

$gq->addServer('source', '127.0.0.1:27015', id: 'my-css-server');
$gq->addServer('minecraft', 'mc.hypixel.net:25565', id: 'hypixel');

foreach ($gq->process() as $result) {
    if (!$result->online) {
        printf("[%s] OFFLINE (%s)\n", $result->server->label(), $result->error ?? 'no response');
        continue;
    }

    printf(
        "[%s] %s -- %s/%s players -- %.1fms\n",
        $result->server->label(),
        $result->data['name'] ?? '(unnamed)',
        $result->data['players'] ?? '?',
        $result->data['max_players'] ?? '?',
        $result->pingMs
    );
}
