<?php

/**
 * Dependency-free smoke tests for the parts of the library that don't
 * require a live game server to exercise: binary buffer round-tripping
 * and protocol packet parsing against known-good captured byte layouts.
 *
 * Run with: php tests/smoke_test.php
 * A live-server integration example lives in examples/basic.php instead,
 * since CI/sandboxed environments generally can't reach a real game server.
 */

require __DIR__ . '/../autoload.php';

use GameQuery\Buffer\ByteReader;
use GameQuery\Buffer\ByteWriter;
use GameQuery\Protocol\Minecraft;
use GameQuery\Protocol\Bedrock;
use GameQuery\Protocol\FiveM;
use GameQuery\Protocol\Palworld;
use GameQuery\Protocol\Source;
use GameQuery\Server;

$failures = 0;
$passed = 0;

function check(string $description, bool $condition): void
{
    global $failures, $passed;

    if ($condition) {
        $passed++;
        echo "  ok  - {$description}\n";
    } else {
        $failures++;
        echo "FAIL  - {$description}\n";
    }
}

echo "ByteReader / ByteWriter round-trips\n";

$w = (new ByteWriter())->writeUInt8(200)->writeInt32(-5)->writeCString('hello');
$r = new ByteReader($w->toString());
check('uint8 round-trips', $r->readUInt8() === 200);
check('int32 round-trips (negative)', $r->readInt32() === -5);
check('cstring round-trips', $r->readCString() === 'hello');

$vw = (new ByteWriter())->writeVarInt(300);
$vr = new ByteReader($vw->toString());
check('varint round-trips', $vr->readVarInt() === 300);

echo "\nSource (A2S_INFO) parsing against a hand-built known-good packet\n";

// Build a synthetic A2S_INFO response byte-for-byte per the documented
// Valve layout, then confirm our parser reads it back correctly.
$infoPacket = "\xFF\xFF\xFF\xFF" . "\x49" // header + type 'I'
    . "\x11"                              // protocol version 17
    . "My Test Server\x00"
    . "de_dust2\x00"
    . "csgo\x00"
    . "Counter-Strike: Global Offensive\x00"
    . pack('v', 730)                      // app id
    . "\x0A"                              // players = 10
    . "\x14"                              // max players = 20
    . "\x02"                              // bots = 2
    . "d"                                 // dedicated
    . "l"                                 // linux
    . "\x00"                              // not password protected
    . "\x01"                              // VAC secured
    . "1.38\x00";                         // version string

$source = new Source();
$server = new Server('source', '127.0.0.1', 27015);
$parsed = $source->parse($server, [['tag' => 'info', 'request' => '', 'response' => $infoPacket]]);

check('server name parsed', $parsed['name'] === 'My Test Server');
check('map parsed', $parsed['map'] === 'de_dust2');
check('game parsed', $parsed['game'] === 'Counter-Strike: Global Offensive');
check('app_id parsed', $parsed['app_id'] === 730);
check('players parsed', $parsed['players'] === 10);
check('max_players parsed', $parsed['max_players'] === 20);
check('server_type decoded', $parsed['server_type'] === 'dedicated');
check('environment decoded', $parsed['environment'] === 'linux');
check('vac_secured decoded', $parsed['vac_secured'] === true);

echo "\nSource challenge extraction drives the player-query step correctly\n";

$challengeResponse = "\xFF\xFF\xFF\xFF" . "A" . pack('V', 0xDEADBEEF);
$history = [
    ['tag' => 'info', 'request' => '', 'response' => $infoPacket],
    ['tag' => 'player_challenge', 'request' => '', 'response' => $challengeResponse],
];
$next = $source->nextStep($server, $history);
check('next step targets player_data', $next['tag'] === 'player_data');
check('challenge bytes carried into next packet', str_ends_with($next['packet'], pack('V', 0xDEADBEEF)));

echo "\nMinecraft status packet framing + JSON parsing\n";

$statusJson = json_encode([
    'version' => ['name' => '1.21', 'protocol' => 767],
    'players' => ['online' => 5, 'max' => 20, 'sample' => [['name' => 'Steve', 'id' => '...']]],
    'description' => ['text' => 'A Minecraft Server'],
]);
$body = (new ByteWriter())->writeVarInt(0x00)->writeMcString($statusJson)->toString();
$framed = (new ByteWriter())->writeVarInt(strlen($body))->writeRaw($body)->toString();

$mc = new Minecraft();
check('reports complete once full length is present', $mc->isResponseComplete($framed));
check('reports incomplete when truncated', !$mc->isResponseComplete(substr($framed, 0, 3)));

$mcServer = new Server('minecraft', 'mc.example.com', 25565);
$mcParsed = $mc->parse($mcServer, [['tag' => 'status', 'request' => '', 'response' => $framed]]);
check('minecraft name parsed', $mcParsed['name'] === 'A Minecraft Server');
check('minecraft player count parsed', $mcParsed['players'] === 5);
check('minecraft max players parsed', $mcParsed['max_players'] === 20);
check('minecraft sample player list parsed', $mcParsed['players_list'] === ['Steve']);

echo "\nPalworld REST API request building + response parsing\n";

$palServer = new Server('palworld', '203.0.113.10', 8212, options: ['password' => 'secret123']);
$palworld = new Palworld();

$initial = $palworld->initialStep($palServer);
check('initial request targets /v1/api/info', str_contains($initial['packet'], 'GET /v1/api/info'));
check('initial request carries Basic auth for admin:secret123', str_contains(
    $initial['packet'],
    'Authorization: Basic ' . base64_encode('admin:secret123')
));

$palServerNoPassword = new Server('palworld', '203.0.113.10', 8212);
$threw = false;
try {
    $palworld->initialStep($palServerNoPassword);
} catch (\GameQuery\Exception\GameQueryException) {
    $threw = true;
}
check('missing password throws a catchable exception', $threw);

$infoResponseBody = json_encode(['servername' => 'Test Pal Server', 'version' => 'v0.4.1']);
$infoResponse = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($infoResponseBody) . "\r\n\r\n" . $infoResponseBody;
check('reports complete once Content-Length bytes are present', $palworld->isResponseComplete($infoResponse));
check('reports incomplete when body is truncated', !$palworld->isResponseComplete(substr($infoResponse, 0, -5)));

$palHistory = [['tag' => 'info', 'request' => '', 'response' => $infoResponse]];
$palParsed = $palworld->parse($palServer, $palHistory);
check('palworld server name parsed', $palParsed['name'] === 'Test Pal Server');
check('palworld version parsed', $palParsed['version'] === 'v0.4.1');

$next = $palworld->nextStep($palServer, $palHistory);
check('next step requests the players endpoint', $next !== null && str_contains($next['packet'], 'GET /v1/api/players'));

$unauthorizedResponse = "HTTP/1.1 401 Unauthorized\r\nContent-Length: 2\r\n\r\n{}";
$authFailParsed = $palworld->parse($palServer, [['tag' => 'info', 'request' => '', 'response' => $unauthorizedResponse]]);
check('401 response surfaces as auth_error rather than crashing', $authFailParsed['auth_error'] === true);

echo "\nMinecraft Bedrock (RakNet Unconnected Pong) MOTD parsing\n";
$bedrock = new Bedrock();
$bedrockServer = Server::fromAddress('bedrock', '127.0.0.1:19132');
$motd = 'MCPE;My Bedrock Server;800;1.21.0;12;40;12345;Bedrock level;Survival;1;19132;19133;';
$pong = "\x1c"
    . pack('J', 111) // server time
    . pack('J', 222) // server GUID
    . "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78" // MAGIC
    . pack('n', strlen($motd))
    . $motd;
$bedrockParsed = $bedrock->parse($bedrockServer, [['tag' => 'ping', 'request' => '', 'response' => $pong]]);
check('bedrock server name parsed', $bedrockParsed['name'] === 'My Bedrock Server');
check('bedrock version parsed', $bedrockParsed['version'] === '1.21.0');
check('bedrock player count parsed', $bedrockParsed['players'] === 12);
check('bedrock max players parsed', $bedrockParsed['max_players'] === 40);
check('bedrock world name parsed as map', ($bedrockParsed['map'] ?? null) === 'Bedrock level');
check('bedrock ping is a single-shot conversation', $bedrock->nextStep($bedrockServer, []) === null);

echo "\nFiveM HTTP JSON parsing\n";
$fivem = new FiveM();
$fivemServer = Server::fromAddress('fivem', '127.0.0.1:30120');
$infoBody = json_encode(['server' => 'FXServer v1.0', 'vars' => ['sv_projectName' => 'Test RP', 'sv_maxClients' => '48', 'mapname' => 'San Andreas', 'gametype' => 'Roleplay']]);
$infoResp = "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($infoBody) . "\r\n\r\n" . $infoBody;
$playersBody = json_encode([['name' => 'Alice'], ['name' => 'Bob'], ['name' => 'Carol']]);
$playersResp = "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($playersBody) . "\r\n\r\n" . $playersBody;
$fivemHistory = [
    ['tag' => 'info', 'request' => '', 'response' => $infoResp],
    ['tag' => 'players', 'request' => '', 'response' => $playersResp],
];
$fivemParsed = $fivem->parse($fivemServer, $fivemHistory);
check('fivem name parsed from vars', $fivemParsed['name'] === 'Test RP');
check('fivem max players parsed', $fivemParsed['max_players'] === 48);
check('fivem gametype parsed', ($fivemParsed['gametype'] ?? null) === 'Roleplay');
check('fivem player count from players.json', $fivemParsed['players'] === 3);
check('fivem player names parsed', $fivemParsed['players_list'] === ['Alice', 'Bob', 'Carol']);
$fivemNext = $fivem->nextStep($fivemServer, [['tag' => 'info', 'request' => '', 'response' => $infoResp]]);
check('fivem next step requests players.json', $fivemNext !== null && str_contains($fivemNext['packet'], 'GET /players.json'));

echo "\n" . ($failures === 0 ? "All {$passed} checks passed.\n" : "{$failures} of " . ($passed + $failures) . " checks FAILED.\n");
exit($failures === 0 ? 0 : 1);
