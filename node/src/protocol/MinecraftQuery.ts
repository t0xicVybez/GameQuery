import { ByteReader } from '../buffer/ByteReader.js';
import { AbstractProtocol } from './AbstractProtocol.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * Minecraft's Query protocol (UDP) — the GameSpy4/UT3-style query a Java server
 * exposes when `enable-query=true` is set (default query port = the game port).
 * Unlike the Server List Ping (`minecraft`), the full-stat query returns the
 * complete player list rather than SLP's truncated sample.
 *
 * Conversation (challenge/response, two round trips):
 *   1. handshake  ->  \xFE\xFD\x09 <sessionId:4>
 *      reply       <-  \x09 <sessionId:4> <challenge ascii int\0>
 *   2. full stat  ->  \xFE\xFD\x00 <sessionId:4> <challenge:int32 BE> \x00\x00\x00\x00
 *      reply       <-  \x00 <sessionId:4> splitnum\x00\x80\x00 <key\0val\0..\0\0>
 *                      \x01player_\x00\x00 <name\0..\0>
 *
 * Reads the first datagram only; a very large roster split across `splitnum`
 * packets isn't fully reassembled.
 */
export class MinecraftQuery extends AbstractProtocol {
  private static readonly SESSION_ID = Buffer.from([0x00, 0x00, 0x00, 0x01]);

  static protocolName(): string {
    return 'minecraft-query';
  }

  transport(): Transport {
    return 'udp';
  }

  initialStep(_server: Server): Step {
    return {
      tag: 'challenge',
      packet: Buffer.concat([Buffer.from([0xfe, 0xfd, 0x09]), MinecraftQuery.SESSION_ID]),
    };
  }

  nextStep(_server: Server, history: HistoryEntry[]): Step | null {
    if (this.hasTag(history, 'info')) return null;
    const reply = this.responseFor(history, 'challenge');
    if (reply === null) return null;

    // Reply: \x09 <sessionId:4> <ascii challenge, null-terminated>
    const challengeStr = reply
      .subarray(5)
      .toString('latin1')
      .replace(/\x00.*$/, '')
      .trim();
    const challenge = Number.parseInt(challengeStr, 10) | 0;
    const chalBuf = Buffer.alloc(4);
    chalBuf.writeInt32BE(challenge, 0);

    const packet = Buffer.concat([
      Buffer.from([0xfe, 0xfd, 0x00]),
      MinecraftQuery.SESSION_ID,
      chalBuf,
      Buffer.from([0x00, 0x00, 0x00, 0x00]), // full-stat request padding
    ]);
    return { tag: 'info', packet };
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const raw = this.responseFor(history, 'info');
    if (raw === null) return {};

    const r = new ByteReader(raw);
    r.skip(5); // type + session id
    let rest = r.read(r.remaining()).toString('latin1');

    const splitPos = rest.indexOf('splitnum\x00');
    if (splitPos !== -1) {
      rest = rest.slice(splitPos + 9 + 1).replace(/^\x00+/, '');
    }

    const playersMarker = rest.indexOf('\x01player_\x00');
    const kvBlock = playersMarker !== -1 ? rest.slice(0, playersMarker) : rest;
    const cvars = this.parseKeyValues(kvBlock);

    const playersList: string[] = [];
    if (playersMarker !== -1) {
      const names = rest.slice(playersMarker + '\x01player_\x00\x00'.length);
      for (const name of names.split('\x00')) {
        if (name === '') break;
        playersList.push(name);
      }
    }

    const numPlayers = cvars.numplayers !== undefined ? Number(cvars.numplayers) : playersList.length;
    const result: Record<string, unknown> = {
      name: cvars.hostname ?? 'Minecraft Server',
      map: cvars.map ?? null,
      players: numPlayers,
      max_players: cvars.maxplayers !== undefined ? Number(cvars.maxplayers) : 0,
      players_list: playersList,
      password_protected: false,
      rules: cvars,
    };
    if (cvars.version) result.version = cvars.version;
    if (cvars.gametype) result.gametype = cvars.gametype;
    if (cvars.plugins) result.plugins = cvars.plugins;
    return result;
  }

  private parseKeyValues(block: string): Record<string, string> {
    const parts = block.split('\x00');
    const out: Record<string, string> = {};
    for (let i = 0; i + 1 < parts.length; i += 2) {
      const key = parts[i] as string;
      if (key === '') break;
      out[key] = parts[i + 1] as string;
    }
    return out;
  }
}
