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

console.log('\n' + (failures === 0 ? `All ${passed} checks passed.` : `${failures} of ${passed + failures} checks FAILED.`));
process.exit(failures === 0 ? 0 : 1);
