/* Parsing smoke tests — mirror the PHP suite, verifying the port produces the
 * same structured results from the same crafted packets. Run: npm test */
import { ByteReader } from '../buffer/ByteReader.js';
import { ByteWriter } from '../buffer/ByteWriter.js';
import { Server } from '../Server.js';
import { Source } from '../protocol/Source.js';
import { Minecraft } from '../protocol/Minecraft.js';
import { Bedrock } from '../protocol/Bedrock.js';
import { Palworld } from '../protocol/Palworld.js';
import { FiveM } from '../protocol/FiveM.js';
import { Quake2 } from '../protocol/Quake2.js';
import { Quake3 } from '../protocol/Quake3.js';
import { GameSpy1 } from '../protocol/GameSpy1.js';
import { GameSpy2 } from '../protocol/GameSpy2.js';
import { GameSpy3 } from '../protocol/GameSpy3.js';
import { Unreal2 } from '../protocol/Unreal2.js';
import { Doom3 } from '../protocol/Doom3.js';
import { Ase } from '../protocol/Ase.js';
import { Mumble } from '../protocol/Mumble.js';
import { Frostbite } from '../protocol/Frostbite.js';
import { AssettoCorsa } from '../protocol/AssettoCorsa.js';
import { TeamSpeak3 } from '../protocol/TeamSpeak3.js';
import { Terraria } from '../protocol/Terraria.js';
import { Samp } from '../protocol/Samp.js';
import { QuakeWorld } from '../protocol/QuakeWorld.js';
import { MinecraftLegacy } from '../protocol/MinecraftLegacy.js';
import type { HistoryEntry } from '../types.js';

let passed = 0;
let failures = 0;
function check(desc: string, cond: boolean): void {
  if (cond) {
    passed++;
    console.log(`  ok  - ${desc}`);
  } else {
    failures++;
    console.log(`FAIL  - ${desc}`);
  }
}
const hist = (tag: string, response: Buffer): HistoryEntry[] => [{ tag, request: Buffer.alloc(0), response }];
const srv = (proto: string, addr: string) => Server.fromAddress(proto, addr);

console.log('ByteReader / ByteWriter round-trips');
const w = new ByteWriter().writeUInt8(0xff).writeInt32(305419896).writeVarInt(300).writeCString('hi').writeMcString('yo');
const rr = new ByteReader(w.toBuffer());
check('uint8 round-trips', rr.readUInt8() === 0xff);
check('int32 LE round-trips', rr.readInt32() === 305419896);
check('varint round-trips', rr.readVarInt() === 300);
check('cstring round-trips', rr.readCString() === 'hi');
check('varint-length mc string round-trips', rr.readVarInt() === 2 && rr.read(2).toString() === 'yo');
check('uint16BE reads big-endian', new ByteReader(Buffer.from([0x12, 0x34])).readUInt16BE() === 0x1234);

console.log('\nSource (A2S_INFO) parsing');
const info = Buffer.concat([
  Buffer.from([0xff, 0xff, 0xff, 0xff, 0x49, 17]),
  Buffer.from('My CS2 Server\x00de_dust2\x00csgo\x00Counter-Strike 2\x00', 'utf8'),
  Buffer.from([0xda, 0x02, 12, 32, 0, 100, 108, 0, 0]), // appid 730, players 12, max 32, bots 0, type d, env l, pw 0, vac 0
  Buffer.from('1.0\x00', 'utf8'),
]);
const sp = new Source().parse(srv('source', '1.2.3.4:27015'), hist('info', info));
check('source name parsed', sp.name === 'My CS2 Server');
check('source map parsed', sp.map === 'de_dust2');
check('source players parsed', sp.players === 12 && sp.max_players === 32);
check('source challenge drives player step', new Source().nextStep(srv('source', 'x:1'), hist('info', info)) !== null);

// A2S_INFO challenge round-trip (Valve 2020 anti-spoof): a 0x41 reply to A2S_INFO
// must be echoed back before the server sends the real info payload.
const infoChallenge = Buffer.from([0xff, 0xff, 0xff, 0xff, 0x41, 0x44, 0x33, 0x22, 0x11]);
const retryStep = new Source().nextStep(srv('source', 'x:1'), hist('info', infoChallenge));
check('challenged A2S_INFO triggers info_retry', retryStep !== null && retryStep.tag === 'info_retry');
check('info_retry appends the 4-byte challenge', retryStep !== null && retryStep.packet.subarray(-4).equals(Buffer.from([0x44, 0x33, 0x22, 0x11])));
const retryHist: HistoryEntry[] = [
  { tag: 'info', request: Buffer.alloc(0), response: infoChallenge },
  { tag: 'info_retry', request: Buffer.alloc(0), response: info },
];
check('info parsed from the challenge-completed reply', new Source().parse(srv('source', 'x:1'), retryHist).name === 'My CS2 Server');

// "The Ship" (app_id 2400) inserts mode/witnesses/duration before the version.
const shipInfo = Buffer.concat([
  Buffer.from([0xff, 0xff, 0xff, 0xff, 0x49, 7]),
  Buffer.from('Ship Server\x00ship_lobby\x00ship\x00The Ship\x00', 'utf8'),
  Buffer.from([0x60, 0x09, 5, 8, 0, 100, 119, 0, 0]), // appid 2400, players 5, max 8, bots 0, d, w, pw 0, vac 0
  Buffer.from([2, 3, 4]),                              // ship mode / witnesses / duration
  Buffer.from('1.0.0.4\x00', 'utf8'),
]);
const ship = new Source().parse(srv('source', 'x:1'), hist('info', shipInfo));
check('the ship: app_id parsed', ship.app_id === 2400);
check('the ship: extra 3 bytes consumed', ship.ship_mode === 2 && ship.ship_witnesses === 3 && ship.ship_duration === 4);
check('the ship: version still aligned after extra bytes', ship.version === '1.0.0.4');

console.log('\nMinecraft framing + JSON');
const mcJson = JSON.stringify({ description: { text: 'A Minecraft Server' }, version: { name: '1.21', protocol: 767 }, players: { online: 5, max: 20, sample: [{ name: 'Steve' }] } });
const mcInner = new ByteWriter().writeVarInt(0x00).writeMcString(mcJson).toBuffer();
const mcFramed = new ByteWriter().writeVarInt(mcInner.length).writeRaw(mcInner).toBuffer();
const mcp = new Minecraft().parse(srv('minecraft', 'x:25565'), hist('status', mcFramed));
check('minecraft name parsed', mcp.name === 'A Minecraft Server');
check('minecraft players parsed', mcp.players === 5 && mcp.max_players === 20);
check('minecraft sample parsed', JSON.stringify(mcp.players_list) === JSON.stringify(['Steve']));
check('minecraft frame incomplete detected', new Minecraft().isResponseComplete(Buffer.from([0x80, 0x01])) === false);

console.log('\nMinecraft Bedrock MOTD');
const motd = 'MCPE;My Bedrock Server;800;1.21.0;12;40;12345;Bedrock level;Survival;1;19132;19133;';
const time = Buffer.alloc(8); const guid = Buffer.alloc(8); const len = Buffer.alloc(2); len.writeUInt16BE(motd.length, 0);
const pong = Buffer.concat([Buffer.from([0x1c]), time, guid, Buffer.from('00ffff00fefefefefdfdfdfd12345678', 'hex'), len, Buffer.from(motd, 'utf8')]);
const bp = new Bedrock().parse(srv('bedrock', 'x:19132'), hist('ping', pong));
check('bedrock name parsed', bp.name === 'My Bedrock Server');
check('bedrock counts parsed', bp.players === 12 && bp.max_players === 40);
check('bedrock version parsed', bp.version === '1.21.0');

console.log('\nPalworld / FiveM HTTP');
const palInfo = Buffer.from('HTTP/1.1 200 OK\r\nContent-Length: 46\r\n\r\n{"servername":"Test Pal","version":"v0.4.1"}', 'utf8');
const pp = new Palworld().parse(srv('palworld', 'x:8212'), hist('info', palInfo));
check('palworld name parsed', pp.name === 'Test Pal');
const pal401 = Buffer.from('HTTP/1.1 401 Unauthorized\r\nContent-Length: 2\r\n\r\n{}', 'utf8');
check('palworld 401 -> auth_error', new Palworld().parse(srv('palworld', 'x:8212'), hist('info', pal401)).auth_error === true);
const fmInfoBody = JSON.stringify({ server: 'FX', vars: { sv_projectName: 'Test RP', sv_maxClients: '48', mapname: 'SA', gametype: 'RP' } });
const fmPlayersBody = JSON.stringify([{ name: 'Alice' }, { name: 'Bob' }]);
const fmInfo = Buffer.from(`HTTP/1.1 200 OK\r\nContent-Length: ${Buffer.byteLength(fmInfoBody)}\r\n\r\n${fmInfoBody}`, 'utf8');
const fmPlayers = Buffer.from(`HTTP/1.1 200 OK\r\nContent-Length: ${Buffer.byteLength(fmPlayersBody)}\r\n\r\n${fmPlayersBody}`, 'utf8');
const fp = new FiveM().parse(srv('fivem', 'x:30120'), [...hist('info', fmInfo), ...hist('players', fmPlayers)]);
check('fivem name + max parsed', fp.name === 'Test RP' && fp.max_players === 48);
check('fivem players parsed', fp.players === 2 && JSON.stringify(fp.players_list) === JSON.stringify(['Alice', 'Bob']));

console.log('\nQuake2 / Quake3');
const q3 = Buffer.from('\xff\xff\xff\xffstatusResponse\n\\sv_hostname\\My Q3\\mapname\\q3dm17\\sv_maxclients\\16\n10 30 "P1"\n5 45 "P2"\n', 'latin1');
const q3p = new Quake3().parse(srv('quake3', 'x:27960'), hist('status', q3));
check('quake3 name/map parsed', q3p.name === 'My Q3' && q3p.map === 'q3dm17');
check('quake3 players parsed', q3p.players === 2 && JSON.stringify(q3p.players_list) === JSON.stringify(['P1', 'P2']));
const q2 = Buffer.from('\xff\xff\xff\xffprint\n\\maxclients\\16\\hostname\\My Q2\\mapname\\q2dm1\n20 50 "Ranger"\n', 'latin1');
const q2p = new Quake2().parse(srv('quake2', 'x:27910'), hist('status', q2));
check('quake2 name/max parsed', q2p.name === 'My Q2' && q2p.max_players === 16);

console.log('\nGameSpy 1 / 2 / 3');
const gs1 = Buffer.from('\\hostname\\My UT\\mapname\\CTF-Face\\maxplayers\\16\\player_0\\Loque\\player_1\\Xan\\final\\', 'latin1');
const gs1p = new GameSpy1().parse(srv('gamespy1', 'x:7778'), hist('status', gs1));
check('gamespy1 name/players parsed', gs1p.name === 'My UT' && JSON.stringify(gs1p.players_list) === JSON.stringify(['Loque', 'Xan']));
const gs2 = Buffer.from('\x00\x04\x05\x06\x07hostname\x00My Halo\x00mapname\x00Blood Gulch\x00maxplayers\x0016\x00numplayers\x002\x00\x00\x02player_\x00score_\x00Chief\x00100\x00Cortana\x0080\x00\x00', 'latin1');
const gs2p = new GameSpy2().parse(srv('gamespy2', 'x:23000'), hist('info', gs2));
check('gamespy2 name/max parsed', gs2p.name === 'My Halo' && gs2p.max_players === 16);
check('gamespy2 players parsed', JSON.stringify(gs2p.players_list) === JSON.stringify(['Chief', 'Cortana']));
const gs3Chal = Buffer.from('\x09\x04\x05\x06\x071234567\x00', 'latin1');
const gs3Next = new GameSpy3().nextStep(srv('gamespy3', 'x:29900'), hist('challenge', gs3Chal));
const beChal = Buffer.alloc(4); beChal.writeInt32BE(1234567, 0);
check('gamespy3 challenge is big-endian', gs3Next !== null && gs3Next.packet.includes(beChal));
const gs3Info = Buffer.from('\x00\x04\x05\x06\x07splitnum\x00\x00hostname\x00My BF2\x00mapname\x00Strike\x00maxplayers\x0064\x00numplayers\x002\x00\x00\x01player_\x00\x00Alice\x00Bob\x00\x00', 'latin1');
const gs3p = new GameSpy3().parse(srv('gamespy3', 'x:29900'), hist('info', gs3Info));
check('gamespy3 name/max parsed', gs3p.name === 'My BF2' && gs3p.max_players === 64);
check('gamespy3 players parsed', JSON.stringify(gs3p.players_list) === JSON.stringify(['Alice', 'Bob']));

console.log('\nUnreal 2');
const ustr = (s: string): Buffer => Buffer.concat([Buffer.from([s.length + 1]), Buffer.from(s + '\x00', 'utf8')]);
const i32 = (n: number): Buffer => { const b = Buffer.alloc(4); b.writeInt32LE(n, 0); return b; };
const u2 = Buffer.concat([Buffer.from([0x80, 0, 0, 0, 0]), i32(1), ustr('1.2.3.4'), i32(7777), i32(7787), ustr('My UT2004'), ustr('DM-Rankin'), ustr('DeathMatch'), i32(5), i32(16)]);
const u2p = new Unreal2().parse(srv('unreal2', 'x:7787'), hist('details', u2));
check('unreal2 name/map parsed', u2p.name === 'My UT2004' && u2p.map === 'DM-Rankin');
check('unreal2 counts parsed', u2p.players === 5 && u2p.max_players === 16);

console.log('\nDoom 3 / ASE');
const d3 = Buffer.concat([
  Buffer.from('\xff\xffinfoResponse\x00\x00\x00\x00\x00', 'latin1'),
  Buffer.from('si_name\x00My Doom3\x00si_map\x00d3dm1\x00si_maxPlayers\x008\x00si_version\x00DOOM 1.3.1\x00\x00', 'latin1'),
  Buffer.from([0x00, 0x0f, 0x00, 0, 0, 0, 0]), Buffer.from('Marine\x00', 'latin1'), Buffer.from([0x20]),
]);
const d3p = new Doom3().parse(srv('doom3', 'x:27666'), hist('info', d3));
check('doom3 name/map/max parsed', d3p.name === 'My Doom3' && d3p.map === 'd3dm1' && d3p.max_players === 8);
check('doom3 player parsed', JSON.stringify(d3p.players_list) === JSON.stringify(['Marine']));
const aseStr = (v: string): Buffer => Buffer.concat([Buffer.from([v.length + 1]), Buffer.from(v, 'latin1')]);
const ase = Buffer.concat([
  Buffer.from('EYE1', 'latin1'),
  aseStr('MTA:SA'), aseStr('22003'), aseStr('My MTA'), aseStr('Roleplay'), aseStr('San Andreas'),
  aseStr('1.5'), aseStr('0'), aseStr('2'), aseStr('50'),
  aseStr('buildtype'), aseStr('release'), Buffer.from([0x01]),
  Buffer.from([0x01]), aseStr('Alice'), Buffer.from([0x01]), aseStr('Bob'),
]);
const asep = new Ase().parse(srv('ase', 'x:22126'), hist('info', ase));
check('ase name/map/max parsed', asep.name === 'My MTA' && asep.map === 'San Andreas' && asep.max_players === 50);
check('ase players parsed', asep.players === 2 && JSON.stringify(asep.players_list) === JSON.stringify(['Alice', 'Bob']));

console.log('\nMumble');
const mumble = Buffer.alloc(24);
mumble.writeUInt8(0, 0);
mumble.writeUInt8(1, 1); mumble.writeUInt8(4, 2); mumble.writeUInt8(31, 3); // version 1.4.31
// bytes 4..11 ident echo left as zero
mumble.writeUInt32BE(42, 12);    // users
mumble.writeUInt32BE(100, 16);   // max users
mumble.writeUInt32BE(72000, 20); // bandwidth
const mp = new Mumble().parse(srv('mumble', 'x:64738'), hist('ping', mumble));
check('mumble version/users/max parsed', mp.version === '1.4.31' && mp.players === 42 && mp.max_players === 100);
check('mumble bandwidth parsed', mp.bandwidth === 72000);
check('mumble short packet rejected', Object.keys(new Mumble().parse(srv('mumble', 'x:64738'), hist('ping', Buffer.from([0, 1])))).length === 0);

console.log('\nFrostbite (Battlefield)');
const fbWord = (v: string): Buffer => {
  const wb = Buffer.from(v, 'latin1');
  const len = Buffer.alloc(4);
  len.writeUInt32LE(wb.length, 0);
  return Buffer.concat([len, wb, Buffer.from([0])]);
};
const fbWords = ['OK', 'My BF4 Server', '32', '64', 'ConquestLarge0', 'MP_Prison'];
const fbBody = Buffer.concat(fbWords.map(fbWord));
const fbHeader = Buffer.alloc(12);
fbHeader.writeUInt32LE(0x40000000, 0); // response flag
fbHeader.writeUInt32LE(12 + fbBody.length, 4);
fbHeader.writeUInt32LE(fbWords.length, 8);
const fbResponse = Buffer.concat([fbHeader, fbBody]);
const fb = new Frostbite();
const fbp = fb.parse(srv('frostbite', 'x:47200'), hist('serverInfo', fbResponse));
check('frostbite name/players/max parsed', fbp.name === 'My BF4 Server' && fbp.players === 32 && fbp.max_players === 64);
check('frostbite gamemode/map parsed', fbp.game === 'ConquestLarge0' && fbp.map === 'MP_Prison');
check('frostbite framing incomplete', fb.isResponseComplete(fbResponse.subarray(0, 10)) === false);
check('frostbite framing complete', fb.isResponseComplete(fbResponse) === true);
check('frostbite request framing well-formed', fb.isResponseComplete(fb.initialStep(srv('frostbite', 'x:47200')).packet) === true);

console.log('\nAssetto Corsa');
const acJson = JSON.stringify({
  name: 'Nordschleife Tourist',
  track: 'ks_nordschleife',
  clients: 12,
  maxclients: 24,
  pass: true,
});
const acResponse = Buffer.from(
  `HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ${Buffer.byteLength(acJson)}\r\n\r\n${acJson}`,
  'latin1',
);
const ac = new AssettoCorsa();
const acp = ac.parse(srv('assettocorsa', 'x:8081'), hist('info', acResponse));
check('assettocorsa name/track parsed', acp.name === 'Nordschleife Tourist' && acp.map === 'ks_nordschleife');
check('assettocorsa players/max/password parsed', acp.players === 12 && acp.max_players === 24 && acp.password === true);
check('assettocorsa framing gated by content-length', ac.isResponseComplete(acResponse.subarray(0, acResponse.length - 5)) === false);
check('assettocorsa framing complete', ac.isResponseComplete(acResponse) === true);
check('assettocorsa non-200 rejected', Object.keys(ac.parse(srv('assettocorsa', 'x:8081'), hist('info', Buffer.from('HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n', 'latin1')))).length === 0);

console.log('\nTeamSpeak 3 (ServerQuery)');
const tsResponse = Buffer.from(
  'TS3\r\n' +
    'Welcome to the TeamSpeak 3 ServerQuery interface, type "help" for a list of commands.\r\n' +
    'error id=0 msg=ok\r\n' +
    'virtualserver_unique_identifier=abc123 virtualserver_name=My\\sCool\\sTS3\\sServer ' +
    'virtualserver_maxclients=32 virtualserver_clientsonline=6 virtualserver_channelsonline=10 ' +
    'virtualserver_version=3.13.7 virtualserver_platform=Linux virtualserver_queryclientsonline=1\r\n' +
    'error id=0 msg=ok\r\n',
  'latin1',
);
const ts = new TeamSpeak3();
const tsp = ts.parse(srv('teamspeak3', 'x:10011'), hist('query', tsResponse));
check('teamspeak3 name/max/version parsed', tsp.name === 'My Cool TS3 Server' && tsp.max_players === 32 && tsp.version === '3.13.7');
check('teamspeak3 players exclude query clients', tsp.players === 5);
check('teamspeak3 completion needs two error lines', ts.isResponseComplete(Buffer.from('TS3\r\nerror id=0 msg=ok\r\n', 'latin1')) === false);
check('teamspeak3 completion after serverinfo', ts.isResponseComplete(tsResponse) === true);
check(
  'teamspeak3 request selects voice port',
  ts.initialStep(Server.fromAddress('teamspeak3', 'x:10011', null, { voicePort: 9988 })).packet.toString('latin1').includes('use port=9988'),
);

console.log('\nTerraria (TShock REST)');
const trJson = JSON.stringify({
  status: '200',
  name: 'My Terraria World',
  port: 7777,
  playercount: 2,
  maxplayers: 8,
  world: 'Hallow',
  serverversion: '1.4.4.9',
  players: [{ nickname: 'Alice' }, { nickname: 'Bob' }],
});
const trResponse = Buffer.from(
  `HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ${Buffer.byteLength(trJson)}\r\n\r\n${trJson}`,
  'latin1',
);
const tr = new Terraria();
const trServer = Server.fromAddress('terraria', 'x:7878', null, { token: 'secrettoken' });
const trp = tr.parse(trServer, hist('status', trResponse));
check('terraria name/world/version parsed', trp.name === 'My Terraria World' && trp.map === 'Hallow' && trp.version === '1.4.4.9');
check('terraria players/max parsed', trp.players === 2 && trp.max_players === 8);
check('terraria player names parsed', JSON.stringify(trp.players_list) === JSON.stringify(['Alice', 'Bob']));
check('terraria request carries token', tr.initialStep(trServer).packet.toString('latin1').includes('token=secrettoken'));
let trThrew = false;
try {
  tr.initialStep(Server.fromAddress('terraria', 'x:7878'));
} catch {
  trThrew = true;
}
check('terraria missing token rejected', trThrew === true);

console.log('\nSA-MP / open.mp');
const sampHeader = (op: string): Buffer =>
  Buffer.concat([Buffer.from('SAMP', 'latin1'), Buffer.from([127, 0, 0, 1]), leU16(7777), Buffer.from(op, 'latin1')]);
function leU16(n: number): Buffer {
  const b = Buffer.alloc(2);
  b.writeUInt16LE(n, 0);
  return b;
}
function leU32(n: number): Buffer {
  const b = Buffer.alloc(4);
  b.writeUInt32LE(n, 0);
  return b;
}
function sampStr32(s: string): Buffer {
  return Buffer.concat([leU32(s.length), Buffer.from(s, 'latin1')]);
}
const sampInfo = Buffer.concat([
  sampHeader('i'),
  Buffer.from([0]), // password: no
  leU16(50),
  leU16(100),
  sampStr32('Los Santos Roleplay'),
  sampStr32('Freeroam'),
  sampStr32('English'),
]);
const sampClients = Buffer.concat([
  sampHeader('c'),
  leU16(2),
  Buffer.from([5]),
  Buffer.from('Alice', 'latin1'),
  leU32(10),
  Buffer.from([3]),
  Buffer.from('Bob', 'latin1'),
  leU32(20),
]);
const samp = new Samp();
const sampServer = srv('samp', '127.0.0.1:7777');
const sampp = samp.parse(sampServer, [
  { tag: 'info', request: Buffer.alloc(0), response: sampInfo },
  { tag: 'players', request: Buffer.alloc(0), response: sampClients },
]);
check('samp name/gametype/language parsed', sampp.name === 'Los Santos Roleplay' && sampp.gametype === 'Freeroam' && sampp.language === 'English');
check('samp players/max/password parsed', sampp.players === 50 && sampp.max_players === 100 && sampp.password === false);
check('samp client list parsed', JSON.stringify(sampp.players_list) === JSON.stringify(['Alice', 'Bob']));
check('samp requires address resolution', samp.requiresAddressResolution() === true);
const sampReq = samp.initialStep(sampServer).packet;
check('samp request has SAMP header + opcode', sampReq.subarray(0, 4).toString('latin1') === 'SAMP' && sampReq[10] === 0x69);
check('samp request embeds ip octets', JSON.stringify([...sampReq.subarray(4, 8)]) === JSON.stringify([127, 0, 0, 1]));
check('samp request embeds LE port', sampReq.readUInt16LE(8) === 7777);
const sampResolved = Server.fromAddress('samp', 'play.example.com:7777').withResolvedIp('203.0.113.5');
check('samp uses resolved ip in packet', JSON.stringify([...samp.initialStep(sampResolved).packet.subarray(4, 8)]) === JSON.stringify([203, 0, 113, 5]));

console.log('\nQuakeWorld');
const qwResponse = Buffer.from(
  '\xff\xff\xff\xffn' +
    '\\maxclients\\16\\map\\dm6\\hostname\\Frag Palace\\*version\\ezQuake\\*gamedir\\qw\n' +
    '1 12 300 "Ranger" "" 0 0\n' +
    '2 8 250 "Visor" "" 4 4\n',
  'latin1',
);
const qw = new QuakeWorld();
const qwp = qw.parse(srv('quakeworld', 'x:27500'), hist('status', qwResponse));
check('quakeworld hostname/map/max parsed', qwp.name === 'Frag Palace' && qwp.map === 'dm6' && qwp.max_players === 16);
check('quakeworld players parsed', qwp.players === 2 && JSON.stringify(qwp.players_list) === JSON.stringify(['Ranger', 'Visor']));
check('quakeworld gamedir/version exposed', qwp.game === 'qw' && qwp.version === 'ezQuake');
check('quakeworld request is OOB status', qw.initialStep(srv('quakeworld', 'x:27500')).packet.equals(Buffer.from('\xff\xff\xff\xffstatus\n', 'latin1')));

console.log('\nMinecraft legacy (pre-1.7)');
const toUtf16Be = (s: string): Buffer => Buffer.from(s, 'utf16le').swap16();
const mclHeader = (payload: Buffer): Buffer => {
  const head = Buffer.alloc(3);
  head[0] = 0xff;
  head.writeUInt16BE(payload.length / 2, 1);
  return Buffer.concat([head, payload]);
};
const mcl = new MinecraftLegacy();
const mclServer = srv('minecraft-legacy', 'x:25565');
// 1.4-1.6: §1 \0 protocol \0 version \0 motd \0 players \0 max
const mclResponse = mclHeader(toUtf16Be('§1\x00127\x001.6.4\x00My Legacy Server\x007\x0020'));
const mclp = mcl.parse(mclServer, hist('ping', mclResponse));
check('minecraft-legacy motd/version/protocol parsed', mclp.name === 'My Legacy Server' && mclp.version === '1.6.4' && mclp.protocol_version === 127);
check('minecraft-legacy players/max parsed', mclp.players === 7 && mclp.max_players === 20);
check('minecraft-legacy framing gated by length', mcl.isResponseComplete(mclResponse.subarray(0, 5)) === false);
check('minecraft-legacy framing complete', mcl.isResponseComplete(mclResponse) === true);
check('minecraft-legacy request is FE01', mcl.initialStep(mclServer).packet.equals(Buffer.from([0xfe, 0x01])));
// beta 1.3: motd § players § max (no leading §1)
const betaResponse = mclHeader(toUtf16Be('A Beta Server§3§10'));
const betap = mcl.parse(mclServer, hist('ping', betaResponse));
check('minecraft-legacy beta motd/players parsed', betap.name === 'A Beta Server' && betap.players === 3 && betap.max_players === 10);

console.log('\n' + (failures === 0 ? `All ${passed} checks passed.` : `${failures} of ${passed + failures} checks FAILED.`));
process.exit(failures === 0 ? 0 : 1);
