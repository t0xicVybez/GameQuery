import { ByteReader } from '../buffer/ByteReader.js';
import { AbstractProtocol } from './AbstractProtocol.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * GameSpy protocol version 3 (UDP) — Battlefield 2, Crysis, UT3, Quake 4, and
 * other mid-2000s titles. Challenge/response: a handshake yields an ASCII
 * challenge int, which is echoed big-endian in the info request. The reply
 * carries a null-delimited key/value block followed by a player-name block.
 *
 * Reads the first datagram only; large rosters split across `splitnum`-indexed
 * packets are not fully reassembled.
 */
export class GameSpy3 extends AbstractProtocol {
  private static readonly SESSION_ID = Buffer.from([0x04, 0x05, 0x06, 0x07]);

  static protocolName(): string {
    return 'gamespy3';
  }

  transport(): Transport {
    return 'udp';
  }

  initialStep(_server: Server): Step {
    return { tag: 'challenge', packet: Buffer.concat([Buffer.from([0xfe, 0xfd, 0x09]), GameSpy3.SESSION_ID]) };
  }

  nextStep(_server: Server, history: HistoryEntry[]): Step | null {
    if (this.hasTag(history, 'info')) return null;
    const reply = this.responseFor(history, 'challenge');
    if (reply === null) return null;

    // Reply: \x09 <sessionId:4> <ascii challenge, null-terminated>
    const challengeStr = reply.subarray(5).toString('latin1').replace(/\x00.*$/, '').trim();
    const challenge = Number.parseInt(challengeStr, 10) | 0;
    const chalBuf = Buffer.alloc(4);
    chalBuf.writeInt32BE(challenge, 0);

    const packet = Buffer.concat([
      Buffer.from([0xfe, 0xfd, 0x00]),
      GameSpy3.SESSION_ID,
      chalBuf,
      Buffer.from([0xff, 0xff, 0xff, 0x01]),
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

    let playersMarker = rest.indexOf('\x00\x01player_\x00');
    if (playersMarker === -1) playersMarker = rest.indexOf('player_\x00\x00');

    const kvBlock = playersMarker !== -1 ? rest.slice(0, playersMarker) : rest;
    const cvars = this.parseKeyValues(kvBlock);

    const playersList: string[] = [];
    if (playersMarker !== -1) {
      const playerBlock = rest.slice(playersMarker);
      const pStart = playerBlock.indexOf('player_\x00');
      if (pStart !== -1) {
        const names = playerBlock.slice(pStart + 'player_\x00'.length + 1);
        for (const name of names.split('\x00')) {
          if (name === '' || name.startsWith('team_t')) break;
          playersList.push(name);
        }
      }
    }

    const numPlayers = cvars.numplayers !== undefined ? Number(cvars.numplayers) : playersList.length;
    const result: Record<string, unknown> = {
      name: cvars.hostname ?? 'GameSpy Server',
      map: cvars.mapname ?? null,
      max_players: cvars.maxplayers !== undefined ? Number(cvars.maxplayers) : 0,
      players: numPlayers,
      players_list: playersList,
      password_protected: cvars.password !== undefined ? Boolean(Number(cvars.password)) : false,
      rules: cvars,
    };
    if (cvars.gametype) result.gametype = cvars.gametype;
    if (cvars.gamever) result.version = cvars.gamever;
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
