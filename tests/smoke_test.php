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
use GameQuery\ErrorCode;
use GameQuery\Exception\GameQueryException;
use GameQuery\Protocol\Ase;
use GameQuery\Protocol\AssettoCorsa;
use GameQuery\Protocol\Bedrock;
use GameQuery\Protocol\Doom3;
use GameQuery\Protocol\FiveM;
use GameQuery\Protocol\Frostbite;
use GameQuery\Protocol\GameSpy1;
use GameQuery\Protocol\GameSpy2;
use GameQuery\Protocol\GameSpy3;
use GameQuery\Protocol\Minecraft;
use GameQuery\Protocol\MinecraftLegacy;
use GameQuery\Protocol\MinecraftQuery;
use GameQuery\Protocol\Mumble;
use GameQuery\Protocol\Palworld;
use GameQuery\Protocol\Quake2;
use GameQuery\Protocol\Quake3;
use GameQuery\Protocol\QuakeWorld;
use GameQuery\Protocol\Samp;
use GameQuery\Protocol\Source;
use GameQuery\Protocol\TeamSpeak3;
use GameQuery\Protocol\Terraria;
use GameQuery\Protocol\Unreal2;
use GameQuery\Result;
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
    . 'd'                                 // dedicated
    . 'l'                                 // linux
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

// "The Ship" (app_id 2400) inserts mode/witnesses/duration before the version.
$shipPacket = "\xFF\xFF\xFF\xFF" . "\x49" . "\x07"
    . "Ship Server\x00" . "ship_lobby\x00" . "ship\x00" . "The Ship\x00"
    . pack('v', 2400)                     // app id 2400 -> The Ship
    . "\x05" . "\x08" . "\x00" . 'd' . 'w' . "\x00" . "\x00"
    . "\x02" . "\x03" . "\x04"            // ship mode / witnesses / duration
    . "1.0.0.4\x00";
$ship = $source->parse($server, [['tag' => 'info', 'request' => '', 'response' => $shipPacket]]);
check('the ship: app_id parsed', $ship['app_id'] === 2400);
check('the ship: extra 3 bytes consumed', $ship['ship_mode'] === 2 && $ship['ship_witnesses'] === 3 && $ship['ship_duration'] === 4);
check('the ship: version still aligned after extra bytes', $ship['version'] === '1.0.0.4');

echo "\nSource challenge extraction drives the player-query step correctly\n";

$challengeResponse = "\xFF\xFF\xFF\xFF" . 'A' . pack('V', 0xDEADBEEF);
$history = [
    ['tag' => 'info', 'request' => '', 'response' => $infoPacket],
    ['tag' => 'player_challenge', 'request' => '', 'response' => $challengeResponse],
];
$next = $source->nextStep($server, $history);
check('next step targets player_data', $next['tag'] === 'player_data');
check('challenge bytes carried into next packet', str_ends_with($next['packet'], pack('V', 0xDEADBEEF)));

echo "\nSource A2S_INFO challenge round-trip (Valve 2020 anti-spoof)\n";

$infoChallenge = "\xFF\xFF\xFF\xFF" . 'A' . pack('V', 0x11223344);
$retryNext = $source->nextStep($server, [
    ['tag' => 'info', 'request' => '', 'response' => $infoChallenge],
]);
check('challenged A2S_INFO triggers an info_retry step', $retryNext !== null && $retryNext['tag'] === 'info_retry');
check('info_retry echoes the A2S_INFO query', str_contains($retryNext['packet'], "Source Engine Query\x00"));
check('info_retry appends the 4-byte challenge', str_ends_with($retryNext['packet'], pack('V', 0x11223344)));

$retryParsed = $source->parse($server, [
    ['tag' => 'info', 'request' => '', 'response' => $infoChallenge],
    ['tag' => 'info_retry', 'request' => '', 'response' => $infoPacket],
]);
check('info parsed from the challenge-completed reply', $retryParsed['name'] === 'My Test Server');

echo "\nSource A2S multi-packet reassembly\n";

// Split header: \xFE\xFF\xFF\xFF (-2 LE) + id(4) + total(1) + number(1) + size(2) + payload
$frag0 = "\xFE\xFF\xFF\xFF" . "\x01\x00\x00\x00" . "\x02" . "\x00" . "\xE0\x04" . 'PART-A';
$frag1 = "\xFE\xFF\xFF\xFF" . "\x01\x00\x00\x00" . "\x02" . "\x01" . "\xE0\x04" . 'PART-B';
check('reassemble waits until all fragments arrive', $source->reassemble([$frag0]) === null);
check('reassemble joins split payloads in packet order', $source->reassemble([$frag0, $frag1]) === 'PART-APART-B');
check('reassemble reorders out-of-order fragments', $source->reassemble([$frag1, $frag0]) === 'PART-APART-B');
$singlePacket = "\xFF\xFF\xFF\xFF" . 'SINGLE';
check('reassemble returns a single packet unchanged', $source->reassemble([$singlePacket]) === $singlePacket);

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

// minecraft-ping variant: after status it runs the SLP 0x01 ping/pong.
$mcPing = new Minecraft(includePing: true);
$statusOnly = [['tag' => 'status', 'request' => '', 'response' => $framed]];
$pingStep = $mcPing->nextStep($mcServer, $statusOnly);
check('minecraft-ping sends a 0x01 ping after status', $pingStep !== null && $pingStep['tag'] === 'ping');
$sentTs = (int) round(microtime(true) * 1000) - 25; // pretend the ping went out 25ms ago
$pong = (new ByteWriter())->writeVarInt(0x01)->writeRaw(pack('J', $sentTs))->withVarIntLengthPrefix();
$pingParsed = $mcPing->parse($mcServer, [
    ['tag' => 'status', 'request' => '', 'response' => $framed],
    ['tag' => 'ping', 'request' => '', 'response' => $pong],
]);
check('minecraft-ping reports ping_ms from the echoed pong', isset($pingParsed['ping_ms']) && $pingParsed['ping_ms'] >= 25 && $pingParsed['ping_ms'] < 5000);

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

echo "\nQuake3 (id Tech 3 getstatus) parsing\n";
$q3 = new Quake3();
$q3Server = Server::fromAddress('quake3', '127.0.0.1:27960');
$q3Response = "\xff\xff\xff\xffstatusResponse\n"
    . "\\sv_hostname\\My Q3 Server\\mapname\\q3dm17\\sv_maxclients\\16\\gamename\\baseq3\\g_gametype\\0\n"
    . "10 30 \"Player One\"\n"
    . "5 45 \"Player Two\"\n";
$q3Parsed = $q3->parse($q3Server, [['tag' => 'status', 'request' => '', 'response' => $q3Response]]);
check('quake3 hostname parsed', $q3Parsed['name'] === 'My Q3 Server');
check('quake3 map parsed', $q3Parsed['map'] === 'q3dm17');
check('quake3 max players parsed', $q3Parsed['max_players'] === 16);
check('quake3 player count parsed', $q3Parsed['players'] === 2);
check('quake3 player names parsed', $q3Parsed['players_list'] === ['Player One', 'Player Two']);
check('quake3 gamename exposed', ($q3Parsed['game'] ?? null) === 'baseq3');
check('quake3 is single-shot', $q3->nextStep($q3Server, []) === null);

echo "\nGameSpy3 challenge handling + key/value parsing\n";
$gs3 = new GameSpy3();
$gs3Server = Server::fromAddress('gamespy3', '127.0.0.1:29900');
// Challenge reply drives a signed-int challenge into a big-endian info request.
$challengeReply = "\x09\x04\x05\x06\x07" . "1234567\x00";
$gs3Next = $gs3->nextStep($gs3Server, [['tag' => 'challenge', 'request' => '', 'response' => $challengeReply]]);
check('gamespy3 sends info request after challenge', $gs3Next !== null && $gs3Next['tag'] === 'info');
check('gamespy3 encodes challenge big-endian', $gs3Next !== null && str_contains($gs3Next['packet'], pack('N', 1234567)));
$gs3Info = "\x00\x04\x05\x06\x07splitnum\x00\x00"
    . "hostname\x00My BF2 Server\x00mapname\x00Strike\x00maxplayers\x0064\x00numplayers\x002\x00gametype\x00gpm_cq\x00"
    . "\x00\x01player_\x00\x00Alice\x00Bob\x00\x00";
$gs3Parsed = $gs3->parse($gs3Server, [
    ['tag' => 'challenge', 'request' => '', 'response' => $challengeReply],
    ['tag' => 'info', 'request' => '', 'response' => $gs3Info],
]);
check('gamespy3 hostname parsed', $gs3Parsed['name'] === 'My BF2 Server');
check('gamespy3 map parsed', $gs3Parsed['map'] === 'Strike');
check('gamespy3 max players parsed', $gs3Parsed['max_players'] === 64);
check('gamespy3 player names parsed', $gs3Parsed['players_list'] === ['Alice', 'Bob']);

echo "\nUnreal2 length-prefixed binary parsing\n";
$u2 = new Unreal2();
$u2Server = Server::fromAddress('unreal2', '127.0.0.1:7787');
$ustr = static fn (string $s): string => chr(strlen($s) + 1) . $s . "\x00";
$u2Details = "\x80\x00\x00\x00\x00"
    . pack('V', 1)          // server id
    . $ustr('1.2.3.4')      // ip
    . pack('V', 7777)       // game port
    . pack('V', 7787)       // query port
    . $ustr('My UT2004 Server')
    . $ustr('DM-Rankin')
    . $ustr('DeathMatch')
    . pack('V', 5)          // num players
    . pack('V', 16);        // max players
$u2Parsed = $u2->parse($u2Server, [['tag' => 'details', 'request' => '', 'response' => $u2Details]]);
check('unreal2 name parsed', $u2Parsed['name'] === 'My UT2004 Server');
check('unreal2 map parsed', $u2Parsed['map'] === 'DM-Rankin');
check('unreal2 gametype parsed', $u2Parsed['gametype'] === 'DeathMatch');
check('unreal2 player count parsed', $u2Parsed['players'] === 5);
check('unreal2 max players parsed', $u2Parsed['max_players'] === 16);

echo "\nQuake2 (id Tech 2 status) parsing\n";
$q2 = new Quake2();
$q2Server = Server::fromAddress('quake2', '127.0.0.1:27910');
$q2Response = "\xff\xff\xff\xffprint\n"
    . "\\maxclients\\16\\hostname\\My Q2 Server\\mapname\\q2dm1\\gamename\\baseq2\n"
    . "20 50 \"Ranger\"\n"
    . "12 80 \"Grunt\"\n";
$q2Parsed = $q2->parse($q2Server, [['tag' => 'status', 'request' => '', 'response' => $q2Response]]);
check('quake2 hostname parsed', $q2Parsed['name'] === 'My Q2 Server');
check('quake2 map parsed', $q2Parsed['map'] === 'q2dm1');
check('quake2 max players parsed', $q2Parsed['max_players'] === 16);
check('quake2 player names parsed', $q2Parsed['players_list'] === ['Ranger', 'Grunt']);

echo "\nGameSpy1 (text \\status\\) parsing\n";
$gs1 = new GameSpy1();
$gs1Server = Server::fromAddress('gamespy1', '127.0.0.1:7778');
$gs1Response = '\\hostname\\My UT Server\\mapname\\CTF-Face\\gametype\\CTF\\numplayers\\2\\maxplayers\\16'
    . '\\player_0\\Loque\\frags_0\\15\\player_1\\Xan\\frags_1\\20\\final\\\\queryid\\1.1';
$gs1Parsed = $gs1->parse($gs1Server, [['tag' => 'status', 'request' => '', 'response' => $gs1Response]]);
check('gamespy1 hostname parsed', $gs1Parsed['name'] === 'My UT Server');
check('gamespy1 map parsed', $gs1Parsed['map'] === 'CTF-Face');
check('gamespy1 max players parsed', $gs1Parsed['max_players'] === 16);
check('gamespy1 players ordered by suffix', $gs1Parsed['players_list'] === ['Loque', 'Xan']);
check('gamespy1 gametype parsed', ($gs1Parsed['gametype'] ?? null) === 'CTF');

echo "\nGameSpy2 (binary rules + players) parsing\n";
$gs2 = new GameSpy2();
$gs2Server = Server::fromAddress('gamespy2', '127.0.0.1:23000');
// \x00 <id:4> then rules (key\0value\0.. empty key) then players (fieldcount, fields, rows)
$gs2Response = "\x00\x04\x05\x06\x07"
    . "hostname\x00My Halo Server\x00mapname\x00Blood Gulch\x00numplayers\x002\x00maxplayers\x0016\x00gametype\x00CTF\x00"
    . "\x00"                                  // empty key terminates rules
    . "\x02"                                  // 2 player fields
    . "player_\x00score_\x00"                 // field names
    . "MasterChief\x00100\x00Cortana\x0080\x00" // 2 player rows
    . "\x00";                                 // empty value ends roster
$gs2Parsed = $gs2->parse($gs2Server, [['tag' => 'info', 'request' => '', 'response' => $gs2Response]]);
check('gamespy2 hostname parsed', $gs2Parsed['name'] === 'My Halo Server');
check('gamespy2 map parsed', $gs2Parsed['map'] === 'Blood Gulch');
check('gamespy2 max players parsed', $gs2Parsed['max_players'] === 16);
check('gamespy2 player names parsed', $gs2Parsed['players_list'] === ['MasterChief', 'Cortana']);

echo "\nDoom3 (id Tech 4 infoResponse) parsing\n";
$d3 = new Doom3();
$d3Server = Server::fromAddress('doom3', '127.0.0.1:27666');
$d3Response = "\xff\xffinfoResponse\x00\x00\x00\x00\x00"
    . "si_name\x00My Doom3 Server\x00si_map\x00game/mp/d3dm1\x00si_maxPlayers\x008\x00gamename\x00DOOM\x00si_version\x00DOOM 1.3.1\x00"
    . "\x00"  // empty key terminates cvars
    . "\x00\x0f\x00\x00\x00\x00\x00Marine\x00"  // one player: id 0, ping 15, rate 0, name
    . "\x20"; // 0x20 end marker
$d3Parsed = $d3->parse($d3Server, [['tag' => 'info', 'request' => '', 'response' => $d3Response]]);
check('doom3 name parsed', $d3Parsed['name'] === 'My Doom3 Server');
check('doom3 map parsed', $d3Parsed['map'] === 'game/mp/d3dm1');
check('doom3 max players parsed', $d3Parsed['max_players'] === 8);
check('doom3 player name parsed', $d3Parsed['players_list'] === ['Marine']);
check('doom3 version exposed', ($d3Parsed['version'] ?? null) === 'DOOM 1.3.1');

echo "\nASE (All-Seeing Eye) parsing\n";
$ase = new Ase();
$aseServer = Server::fromAddress('ase', '127.0.0.1:22126');
$s = static fn (string $v): string => chr(strlen($v) + 1) . $v; // length-prefixed (len incl. itself)
$aseResponse = 'EYE1'
    . $s('MTA:SA') . $s('22003') . $s('My MTA Server') . $s('Roleplay') . $s('San Andreas')
    . $s('1.5') . $s('0') . $s('2') . $s('50')
    . $s('buildtype') . $s('release')       // one rule pair
    . "\x01"                                  // empty key ends rules
    . "\x01" . $s('Alice')                    // player: flags=name only, name
    . "\x01" . $s('Bob');
$aseParsed = $ase->parse($aseServer, [['tag' => 'info', 'request' => '', 'response' => $aseResponse]]);
check('ase hostname parsed', $aseParsed['name'] === 'My MTA Server');
check('ase map parsed', $aseParsed['map'] === 'San Andreas');
check('ase max players parsed', $aseParsed['max_players'] === 50);
check('ase player count parsed', $aseParsed['players'] === 2);
check('ase player names parsed', $aseParsed['players_list'] === ['Alice', 'Bob']);
check('ase game exposed', ($aseParsed['game'] ?? null) === 'MTA:SA');

echo "\nMumble ping parsing\n";
$mumble = new Mumble();
$mumbleServer = Server::fromAddress('mumble', '127.0.0.1:64738');
$mumbleResponse = "\x00" . chr(1) . chr(4) . chr(287 & 0xff) // version 1.4.31
    . pack('J', 0)                                            // ident echo (8 bytes)
    . pack('N', 42)                                           // users
    . pack('N', 100)                                          // max users
    . pack('N', 72000);                                       // bandwidth
$mumbleParsed = $mumble->parse($mumbleServer, [['tag' => 'ping', 'request' => '', 'response' => $mumbleResponse]]);
check('mumble version parsed', $mumbleParsed['version'] === '1.4.31');
check('mumble users parsed', $mumbleParsed['players'] === 42);
check('mumble max users parsed', $mumbleParsed['max_players'] === 100);
check('mumble bandwidth parsed', $mumbleParsed['bandwidth'] === 72000);
check('mumble short packet rejected', $mumble->parse($mumbleServer, [['tag' => 'ping', 'request' => '', 'response' => "\x00\x01"]]) === []);

echo "\nFrostbite (Battlefield) parsing\n";
$fb = new Frostbite();
$fbServer = Server::fromAddress('frostbite', '127.0.0.1:47200');
$word = static fn (string $v): string => pack('V', strlen($v)) . $v . "\x00";
$fbWords = ['OK', 'My BF4 Server', '32', '64', 'ConquestLarge0', 'MP_Prison'];
$fbBody = '';
foreach ($fbWords as $w) {
    $fbBody .= $word($w);
}
$fbResponse = pack('V', 0x40000000) . pack('V', 12 + strlen($fbBody)) . pack('V', count($fbWords)) . $fbBody;
$fbParsed = $fb->parse($fbServer, [['tag' => 'serverInfo', 'request' => '', 'response' => $fbResponse]]);
check('frostbite name parsed', $fbParsed['name'] === 'My BF4 Server');
check('frostbite players parsed', $fbParsed['players'] === 32);
check('frostbite max players parsed', $fbParsed['max_players'] === 64);
check('frostbite gamemode parsed', $fbParsed['game'] === 'ConquestLarge0');
check('frostbite map parsed', $fbParsed['map'] === 'MP_Prison');
check('frostbite non-OK rejected', $fb->parse($fbServer, [['tag' => 'serverInfo', 'request' => '', 'response' => pack('V', 0) . pack('V', 12 + strlen($word('NotFound'))) . pack('V', 1) . $word('NotFound')]]) === []);
// isResponseComplete framing: a partial buffer is not yet complete.
check('frostbite framing incomplete', $fb->isResponseComplete(substr($fbResponse, 0, 10)) === false);
check('frostbite framing complete', $fb->isResponseComplete($fbResponse) === true);
// The request we would send round-trips through our own parser as well-formed.
$fbRequest = $fb->initialStep($fbServer)['packet'];
check('frostbite request framing well-formed', $fb->isResponseComplete($fbRequest) === true);

echo "\nAssetto Corsa parsing\n";
$ac = new AssettoCorsa();
$acServer = Server::fromAddress('assettocorsa', '127.0.0.1:8081');
$acJson = json_encode([
    'name' => 'Nordschleife Tourist',
    'track' => 'ks_nordschleife',
    'clients' => 12,
    'maxclients' => 24,
    'pass' => true,
]);
$acResponse = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($acJson) . "\r\n\r\n" . $acJson;
$acParsed = $ac->parse($acServer, [['tag' => 'info', 'request' => '', 'response' => $acResponse]]);
check('assettocorsa name parsed', $acParsed['name'] === 'Nordschleife Tourist');
check('assettocorsa track/map parsed', $acParsed['map'] === 'ks_nordschleife');
check('assettocorsa players parsed', $acParsed['players'] === 12);
check('assettocorsa max players parsed', $acParsed['max_players'] === 24);
check('assettocorsa password flag parsed', $acParsed['password'] === true);
check('assettocorsa framing gated by content-length', $ac->isResponseComplete(substr($acResponse, 0, strlen($acResponse) - 5)) === false);
check('assettocorsa framing complete', $ac->isResponseComplete($acResponse) === true);
check('assettocorsa non-200 rejected', $ac->parse($acServer, [['tag' => 'info', 'request' => '', 'response' => "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n"]]) === []);

echo "\nTeamSpeak 3 (ServerQuery) parsing\n";
$ts = new TeamSpeak3();
$tsServer = Server::fromAddress('teamspeak3', '127.0.0.1:10011');
$tsResponse = "TS3\r\n"
    . "Welcome to the TeamSpeak 3 ServerQuery interface, type \"help\" for a list of commands.\r\n"
    . "error id=0 msg=ok\r\n"                                 // reply to `use`
    . 'virtualserver_unique_identifier=abc123 virtualserver_name=My\\sCool\\sTS3\\sServer '
    . 'virtualserver_maxclients=32 virtualserver_clientsonline=6 virtualserver_channelsonline=10 '
    . "virtualserver_version=3.13.7 virtualserver_platform=Linux virtualserver_queryclientsonline=1\r\n"
    . "error id=0 msg=ok\r\n";                                // reply to `serverinfo`
$tsParsed = $ts->parse($tsServer, [['tag' => 'query', 'request' => '', 'response' => $tsResponse]]);
check('teamspeak3 name unescaped', $tsParsed['name'] === 'My Cool TS3 Server');
check('teamspeak3 players excl. query clients', $tsParsed['players'] === 5); // 6 online - 1 query
check('teamspeak3 max clients parsed', $tsParsed['max_players'] === 32);
check('teamspeak3 version parsed', $tsParsed['version'] === '3.13.7');
check('teamspeak3 completion needs two error lines', $ts->isResponseComplete("TS3\r\nerror id=0 msg=ok\r\n") === false);
check('teamspeak3 completion after serverinfo', $ts->isResponseComplete($tsResponse) === true);
check('teamspeak3 request selects voice port', str_contains($ts->initialStep(Server::fromAddress('teamspeak3', 'x:10011', null, ['voicePort' => 9988]))['packet'], 'use port=9988'));

echo "\nTerraria (TShock REST) parsing\n";
$tr = new Terraria();
$trServer = Server::fromAddress('terraria', '127.0.0.1:7878', null, ['token' => 'secrettoken']);
$trJson = json_encode([
    'status' => '200',
    'name' => 'My Terraria World',
    'port' => 7777,
    'playercount' => 2,
    'maxplayers' => 8,
    'world' => 'Hallow',
    'serverversion' => '1.4.4.9',
    'players' => [['nickname' => 'Alice'], ['nickname' => 'Bob']],
]);
$trResponse = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($trJson) . "\r\n\r\n" . $trJson;
$trParsed = $tr->parse($trServer, [['tag' => 'status', 'request' => '', 'response' => $trResponse]]);
check('terraria name parsed', $trParsed['name'] === 'My Terraria World');
check('terraria world/map parsed', $trParsed['map'] === 'Hallow');
check('terraria players parsed', $trParsed['players'] === 2);
check('terraria max players parsed', $trParsed['max_players'] === 8);
check('terraria version parsed', $trParsed['version'] === '1.4.4.9');
check('terraria player names parsed', $trParsed['players_list'] === ['Alice', 'Bob']);
check('terraria request carries token', str_contains($tr->initialStep($trServer)['packet'], 'token=secrettoken'));
$trThrew = false;
try {
    $tr->initialStep(Server::fromAddress('terraria', 'x:7878'));
} catch (GameQueryException) {
    $trThrew = true;
}
check('terraria missing token rejected', $trThrew === true);

echo "\nSA-MP / open.mp parsing\n";
$samp = new Samp();
$sampServer = Server::fromAddress('samp', '127.0.0.1:7777');
$sampHeader = static fn (string $op): string => 'SAMP' . chr(127) . chr(0) . chr(0) . chr(1) . pack('v', 7777) . $op;
$sampName = 'Los Santos Roleplay';
$sampGm = 'Freeroam';
$sampLang = 'English';
$sampInfo = $sampHeader('i')
    . chr(0)                                                 // password: no
    . pack('v', 50)                                          // players
    . pack('v', 100)                                         // max players
    . pack('V', strlen($sampName)) . $sampName
    . pack('V', strlen($sampGm)) . $sampGm
    . pack('V', strlen($sampLang)) . $sampLang;
$sampClients = $sampHeader('c') . pack('v', 2)
    . chr(strlen('Alice')) . 'Alice' . pack('V', 10)
    . chr(strlen('Bob')) . 'Bob' . pack('V', 20);
$sampParsed = $samp->parse($sampServer, [
    ['tag' => 'info', 'request' => '', 'response' => $sampInfo],
    ['tag' => 'players', 'request' => '', 'response' => $sampClients],
]);
check('samp name parsed', $sampParsed['name'] === 'Los Santos Roleplay');
check('samp gametype parsed', $sampParsed['gametype'] === 'Freeroam');
check('samp language parsed', $sampParsed['language'] === 'English');
check('samp players/max parsed', $sampParsed['players'] === 50 && $sampParsed['max_players'] === 100);
check('samp password flag parsed', $sampParsed['password'] === false);
check('samp client list parsed', $sampParsed['players_list'] === ['Alice', 'Bob']);
check('samp requires address resolution', $samp->requiresAddressResolution() === true);
$sampReq = $samp->initialStep($sampServer)['packet'];
check('samp request has SAMP header', substr($sampReq, 0, 4) === 'SAMP');
check('samp request embeds ip octets', substr($sampReq, 4, 4) === chr(127) . chr(0) . chr(0) . chr(1));
check('samp request embeds LE port', substr($sampReq, 8, 2) === pack('v', 7777));
check('samp request carries opcode', substr($sampReq, 10, 1) === 'i');
// A resolved host feeds its numeric IP into the packet (address() fallback path).
$sampResolved = Server::fromAddress('samp', 'play.example.com:7777')->withResolvedIp('203.0.113.5');
check('samp uses resolved ip in packet', substr($samp->initialStep($sampResolved)['packet'], 4, 4) === chr(203) . chr(0) . chr(113) . chr(5));

echo "\nQuakeWorld parsing\n";
$qw = new QuakeWorld();
$qwServer = Server::fromAddress('quakeworld', '127.0.0.1:27500');
$qwResponse = "\xFF\xFF\xFF\xFFn"
    . "\\maxclients\\16\\map\\dm6\\hostname\\Frag Palace\\*version\\ezQuake\\*gamedir\\qw\n"
    . "1 12 300 \"Ranger\" \"\" 0 0\n"
    . "2 8 250 \"Visor\" \"\" 4 4\n";
$qwParsed = $qw->parse($qwServer, [['tag' => 'status', 'request' => '', 'response' => $qwResponse]]);
check('quakeworld hostname parsed', $qwParsed['name'] === 'Frag Palace');
check('quakeworld map parsed', $qwParsed['map'] === 'dm6');
check('quakeworld max players parsed', $qwParsed['max_players'] === 16);
check('quakeworld player count parsed', $qwParsed['players'] === 2);
check('quakeworld player names parsed', $qwParsed['players_list'] === ['Ranger', 'Visor']);
check('quakeworld gamedir exposed', ($qwParsed['game'] ?? null) === 'qw');
check('quakeworld version exposed', ($qwParsed['version'] ?? null) === 'ezQuake');
check('quakeworld request is OOB status', $qw->initialStep($qwServer)['packet'] === "\xFF\xFF\xFF\xFFstatus\x0A");

echo "\nMinecraft legacy (pre-1.7) parsing\n";
$mcl = new MinecraftLegacy();
$mclServer = Server::fromAddress('minecraft-legacy', '127.0.0.1:25565');
$toUtf16Be = static function (string $s): string {
    $out = '';
    $i = 0;
    $len = strlen($s);
    while ($i < $len) {
        $c = ord($s[$i]);
        if ($c < 0x80) {
            $cp = $c;
            $i += 1;
        } elseif ($c < 0xE0) {
            $cp = (($c & 0x1F) << 6) | (ord($s[$i + 1]) & 0x3F);
            $i += 2;
        } else {
            $cp = (($c & 0x0F) << 12) | ((ord($s[$i + 1]) & 0x3F) << 6) | (ord($s[$i + 2]) & 0x3F);
            $i += 3;
        }
        $out .= chr(($cp >> 8) & 0xFF) . chr($cp & 0xFF);
    }
    return $out;
};
// 1.4-1.6 payload: §1 \0 protocol \0 version \0 motd \0 players \0 max
$mclPayload = $toUtf16Be("\xC2\xA71\x00127\x001.6.4\x00My Legacy Server\x007\x0020");
$mclResponse = "\xFF" . pack('n', strlen($mclPayload) / 2) . $mclPayload;
$mclParsed = $mcl->parse($mclServer, [['tag' => 'ping', 'request' => '', 'response' => $mclResponse]]);
check('minecraft-legacy motd/name parsed', $mclParsed['name'] === 'My Legacy Server');
check('minecraft-legacy version parsed', $mclParsed['version'] === '1.6.4');
check('minecraft-legacy protocol version parsed', $mclParsed['protocol_version'] === 127);
check('minecraft-legacy players parsed', $mclParsed['players'] === 7);
check('minecraft-legacy max players parsed', $mclParsed['max_players'] === 20);
check('minecraft-legacy framing gated by length', $mcl->isResponseComplete(substr($mclResponse, 0, 5)) === false);
check('minecraft-legacy framing complete', $mcl->isResponseComplete($mclResponse) === true);
check('minecraft-legacy request is FE01', $mcl->initialStep($mclServer)['packet'] === "\xFE\x01");
// beta 1.3 payload: motd § players § max (no leading §1)
$betaPayload = $toUtf16Be("A Beta Server\xC2\xA73\xC2\xA710");
$betaResponse = "\xFF" . pack('n', strlen($betaPayload) / 2) . $betaPayload;
$betaParsed = $mcl->parse($mclServer, [['tag' => 'ping', 'request' => '', 'response' => $betaResponse]]);
check('minecraft-legacy beta motd parsed', $betaParsed['name'] === 'A Beta Server');
check('minecraft-legacy beta players parsed', $betaParsed['players'] === 3 && $betaParsed['max_players'] === 10);

echo "\nMinecraft Query (full stat) parsing\n";

$mcq = new MinecraftQuery();
$mcqServer = new Server('minecraft-query', 'mc.example.com', 25565);

$mcqChallenge = "\x09" . "\x00\x00\x00\x01" . "9513307\x00";
$mcqNext = $mcq->nextStep($mcqServer, [['tag' => 'challenge', 'request' => '', 'response' => $mcqChallenge]]);
check('mcquery full-stat request uses FE FD 00 header', str_starts_with($mcqNext['packet'], "\xFE\xFD\x00"));
check('mcquery encodes the challenge big-endian', str_contains($mcqNext['packet'], pack('N', 9513307)));
check('mcquery full-stat request ends with 4-byte null padding', str_ends_with($mcqNext['packet'], "\x00\x00\x00\x00"));

$mcqResp = "\x00" . "\x00\x00\x00\x01" . "splitnum\x00\x80\x00"
    . "hostname\x00A Minecraft Server\x00gametype\x00SMP\x00version\x001.21\x00map\x00world\x00numplayers\x002\x00maxplayers\x0020\x00"
    . "\x00"
    . "\x01player_\x00\x00"
    . "Steve\x00Alex\x00"
    . "\x00";
$mcqParsed = $mcq->parse($mcqServer, [['tag' => 'info', 'request' => '', 'response' => $mcqResp]]);
check('mcquery name parsed', $mcqParsed['name'] === 'A Minecraft Server');
check('mcquery map parsed', $mcqParsed['map'] === 'world');
check('mcquery player counts parsed', $mcqParsed['players'] === 2 && $mcqParsed['max_players'] === 20);
check('mcquery version parsed', $mcqParsed['version'] === '1.21');
check('mcquery full player list parsed', $mcqParsed['players_list'] === ['Steve', 'Alex']);

echo "\nResult API: normalized accessors, error codes, serializer parity\n";

$taggedServer = new Server('source', '1.2.3.4', 27015, id: 'my-tag');
check('server label uses id when present', $taggedServer->label() === 'my-tag');
check('server label falls back to host:port', (new Server('source', '1.2.3.4', 27015))->label() === '1.2.3.4:27015');

$onlineResult = new Result($taggedServer, true, 42.5, [
    'name' => 'Best Server', 'map' => 'de_dust2', 'players' => 12, 'max_players' => 32,
    'players_list' => [['name' => 'alice', 'score' => 3], 'bob', ['noname' => 1]],
]);
check('result name() accessor', $onlineResult->name() === 'Best Server');
check('result map() accessor', $onlineResult->map() === 'de_dust2');
check('result players()/maxPlayers()', $onlineResult->players() === 12 && $onlineResult->maxPlayers() === 32);
check('result playerNames() flattens objects and bare strings', $onlineResult->playerNames() === ['alice', 'bob']);

$a2sResult = new Result($taggedServer, true, 10.0, ['players_list' => [
    ['index' => 0, 'name' => 'Neo', 'score' => 42, 'duration_sec' => 123.5],
    ['name' => 'Trinity'],
    'Morpheus',
]]);
$pl = $a2sResult->playerList();
check('playerList() keeps score/duration for rich rows', $pl[0] === ['name' => 'Neo', 'score' => 42, 'duration_sec' => 123.5]);
check('playerList() handles name-only objects', $pl[1] === ['name' => 'Trinity']);
check('playerList() promotes bare strings', $pl[2] === ['name' => 'Morpheus']);
check('result toArray()/toObject() parity', $onlineResult->toArray() === $onlineResult->toObject());
check('result serialization carries error_code key', array_key_exists('error_code', $onlineResult->toArray()));

$offlineResult = new Result($taggedServer, false, 0.0, [], 'timeout', ErrorCode::TIMEOUT);
check('offline result carries a typed error code', $offlineResult->errorCode === ErrorCode::TIMEOUT);
check('offline accessors are null-safe', $offlineResult->name() === null && $offlineResult->players() === null && $offlineResult->playerNames() === []);

echo "\n" . ($failures === 0 ? "All {$passed} checks passed.\n" : "{$failures} of " . ($passed + $failures) . " checks FAILED.\n");
exit($failures === 0 ? 0 : 1);
