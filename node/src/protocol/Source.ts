import { ByteReader } from '../buffer/ByteReader.js';
import { AbstractProtocol } from './AbstractProtocol.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * Valve's A2S server query protocol (UDP) — every Source-engine game (CS2,
 * TF2, GMod, ...) and, since A2S is a generic Steamworks feature, many
 * non-Source Steam titles (Rust, ARK, Space Engineers, SCUM, ...).
 *
 * Conversation: A2S_INFO -> [A2S_PLAYER challenge -> A2S_PLAYER] -> [A2S_RULES...].
 * Does not reassemble multi-packet or bzip2-compressed replies (an A2S_RULES
 * edge case on servers with very large cvar lists).
 */
export class Source extends AbstractProtocol {
  private static readonly HEADER = Buffer.from([0xff, 0xff, 0xff, 0xff]);
  private static readonly CHALLENGE_PLACEHOLDER = Buffer.from([0xff, 0xff, 0xff, 0xff]);

  static protocolName(): string {
    return 'source';
  }

  constructor(
    private readonly includePlayers = true,
    private readonly includeRules = false,
  ) {
    super();
  }

  transport(): Transport {
    return 'udp';
  }

  supportsMultiPacket(): boolean {
    return true;
  }

  /**
   * Reassemble a split A2S reply. A single-packet reply (0xFFFFFFFF header) is
   * returned as-is; a split reply (0xFFFFFFFE) is collected by packet number
   * across datagrams and its payloads concatenated once all `Total` arrive.
   * Assumes the modern Source split header (with the 2-byte Size field); bzip2-
   * compressed splits (legacy GoldSource) aren't decompressed.
   */
  reassemble(fragments: Buffer[]): Buffer | null {
    const first = fragments[0];
    if (!first || first.length < 4) return null;

    const header = first.readUInt32LE(0);
    if (header !== 0xfffffffe) {
      return first; // single packet (0xFFFFFFFF) or not A2S — hand it straight to parse()
    }

    const payloads = new Map<number, Buffer>();
    let total = 0;
    let compressed = false;
    for (const frag of fragments) {
      if (frag.length < 12 || frag.readUInt32LE(0) !== 0xfffffffe) continue;
      if (frag.readUInt32LE(4) >= 0x80000000) compressed = true; // high bit = bzip2
      total = frag.readUInt8(8);
      const number = frag.readUInt8(9);
      payloads.set(number, frag.subarray(12)); // skip header(4)+id(4)+total(1)+number(1)+size(2)
    }

    if (compressed || total === 0) return first; // can't reassemble; parse() degrades gracefully
    if (payloads.size < total) return null; // still waiting for datagrams

    const parts: Buffer[] = [];
    for (let i = 0; i < total; i++) {
      const part = payloads.get(i);
      if (!part) return null;
      parts.push(part);
    }
    return Buffer.concat(parts);
  }

  initialStep(_server: Server): Step {
    return {
      tag: 'info',
      packet: Buffer.concat([Source.HEADER, Buffer.from('\x54Source Engine Query\x00', 'latin1')]),
    };
  }

  nextStep(_server: Server, history: HistoryEntry[]): Step | null {
    const infoRaw = this.responseFor(history, 'info');
    if (infoRaw === null) {
      return null;
    }
    // A2S_INFO challenge (Valve, Dec 2020): some servers reply to A2S_INFO with a
    // 0x41 challenge that must be echoed back before they send the real payload.
    if (this.isChallengeReply(infoRaw) && !this.hasTag(history, 'info_retry')) {
      return {
        tag: 'info_retry',
        packet: Buffer.concat([
          Source.HEADER,
          Buffer.from('\x54Source Engine Query\x00', 'latin1'),
          this.extractChallenge(infoRaw),
        ]),
      };
    }
    if (this.includePlayers && !this.hasTag(history, 'player_challenge')) {
      return {
        tag: 'player_challenge',
        packet: Buffer.concat([Source.HEADER, Buffer.from([0x55]), Source.CHALLENGE_PLACEHOLDER]),
      };
    }
    if (
      this.includePlayers &&
      this.hasTag(history, 'player_challenge') &&
      !this.hasTag(history, 'player_data')
    ) {
      const challenge = this.extractChallenge(this.responseFor(history, 'player_challenge'));
      return { tag: 'player_data', packet: Buffer.concat([Source.HEADER, Buffer.from([0x55]), challenge]) };
    }
    if (this.includeRules && !this.hasTag(history, 'rules_challenge')) {
      return {
        tag: 'rules_challenge',
        packet: Buffer.concat([Source.HEADER, Buffer.from([0x56]), Source.CHALLENGE_PLACEHOLDER]),
      };
    }
    if (this.includeRules && this.hasTag(history, 'rules_challenge') && !this.hasTag(history, 'rules_data')) {
      const challenge = this.extractChallenge(this.responseFor(history, 'rules_challenge'));
      return { tag: 'rules_data', packet: Buffer.concat([Source.HEADER, Buffer.from([0x56]), challenge]) };
    }
    return null;
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const result: Record<string, unknown> = {};

    // Prefer the challenge-completed reply when the server required one.
    const info = this.responseFor(history, 'info_retry') ?? this.responseFor(history, 'info');
    if (info !== null) {
      Object.assign(result, this.parseInfo(info));
    }
    const playerData = this.responseFor(history, 'player_data');
    if (playerData !== null) {
      result.players_list = this.parsePlayers(playerData);
    }
    const rulesData = this.responseFor(history, 'rules_data');
    if (rulesData !== null) {
      result.rules = this.parseRules(rulesData);
    }
    return result;
  }

  /** An A2S reply is a challenge when the type byte (after the 0xFFFFFFFF header) is 0x41 ('A'). */
  private isChallengeReply(raw: Buffer | null): boolean {
    return raw !== null && raw.length >= 5 && raw.readUInt8(4) === 0x41;
  }

  private extractChallenge(response: Buffer | null): Buffer {
    if (response === null || response.length < 9) {
      return Source.CHALLENGE_PLACEHOLDER;
    }
    return response.subarray(5, 9); // 0xFF*4, 'A', <4-byte challenge>
  }

  private parseInfo(raw: Buffer): Record<string, unknown> {
    const r = new ByteReader(raw);
    r.skip(4); // header
    const type = r.readUInt8();
    if (type !== 0x49) {
      return { raw_type: type };
    }
    const data: Record<string, unknown> = {
      protocol_version: r.readUInt8(),
      name: r.readCString(),
      map: r.readCString(),
      folder: r.readCString(),
      game: r.readCString(),
      app_id: r.readUInt16(),
      players: r.readUInt8(),
      max_players: r.readUInt8(),
      bots: r.readUInt8(),
      server_type: this.decodeServerType(r.readUInt8()),
      environment: this.decodeEnvironment(r.readUInt8()),
      password_protected: Boolean(r.readUInt8()),
      vac_secured: Boolean(r.readUInt8()),
    };
    // "The Ship" (app_id 2400) is the one A2S game that inserts three extra
    // bytes here — game mode, witness count, and round duration — before the
    // version string. Consume them so the rest of the payload stays aligned.
    if (data.app_id === 2400) {
      data.ship_mode = r.readUInt8();
      data.ship_witnesses = r.readUInt8();
      data.ship_duration = r.readUInt8();
    }
    if (!r.eof()) {
      data.version = r.readCString();
    }
    if (!r.eof()) {
      const edf = r.readUInt8();
      if (edf & 0x80 && r.remaining() >= 2) data.game_port = r.readUInt16();
      if (edf & 0x10 && r.remaining() >= 8) data.steam_id = r.readUInt64();
      if (edf & 0x40 && r.remaining() >= 2) {
        data.spectator_port = r.readUInt16();
        data.spectator_name = r.readCString();
      }
      if (edf & 0x20) data.keywords = r.readCString();
      if (edf & 0x01 && r.remaining() >= 8) data.game_id = r.readUInt64();
    }
    return data;
  }

  private parsePlayers(raw: Buffer): Array<Record<string, unknown>> {
    const r = new ByteReader(raw);
    r.skip(4);
    if (r.readUInt8() !== 0x44) return [];
    const count = r.readUInt8();
    const players: Array<Record<string, unknown>> = [];
    for (let i = 0; i < count && !r.eof(); i++) {
      players.push({
        index: r.readUInt8(),
        name: r.readCString(),
        score: r.readInt32(),
        duration_sec: r.readFloat(),
      });
    }
    return players;
  }

  private parseRules(raw: Buffer): Record<string, string> {
    const r = new ByteReader(raw);
    r.skip(4);
    if (r.readUInt8() !== 0x45) return {};
    const count = r.readUInt16();
    const rules: Record<string, string> = {};
    for (let i = 0; i < count && !r.eof(); i++) {
      rules[r.readCString()] = r.readCString();
    }
    return rules;
  }

  private decodeServerType(byte: number): string {
    return { d: 'dedicated', l: 'listen', p: 'proxy' }[String.fromCharCode(byte)] ?? 'unknown';
  }

  private decodeEnvironment(byte: number): string {
    return { l: 'linux', w: 'windows', m: 'mac', o: 'mac' }[String.fromCharCode(byte)] ?? 'unknown';
  }
}
