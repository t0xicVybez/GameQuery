import type { Server } from './Server.js';
import type { ErrorCodeValue } from './ErrorCode.js';

/** A connected player, normalized across protocols. Only `name` is guaranteed. */
export interface PlayerInfo {
  name: string;
  score?: number;
  durationSec?: number;
}

/**
 * The outcome of querying one server. Always produced — online or not.
 *
 * `data` holds the raw, protocol-specific parse output (field names vary by
 * game). For the fields common to almost every protocol, prefer the normalized
 * accessors — name(), map(), players(), maxPlayers(), playerNames() — which
 * read the right key regardless of protocol and are stable across releases.
 */
export class Result {
  constructor(
    public readonly server: Server,
    public readonly online: boolean,
    public readonly pingMs: number,
    public readonly data: Record<string, unknown> = {},
    public readonly error: string | null = null,
    /** One of ErrorCode.* when offline/errored; null when online and clean. */
    public readonly errorCode: ErrorCodeValue | null = null,
  ) {}

  /** Server/host name as reported by the game, or null if this protocol doesn't provide one. */
  name(): string | null {
    return typeof this.data.name === 'string' ? this.data.name : null;
  }

  /** Current map/level, or null if not applicable to this protocol. */
  map(): string | null {
    return typeof this.data.map === 'string' ? this.data.map : null;
  }

  /** Current player count, or null if unknown. */
  players(): number | null {
    return this.toInt(this.data.players);
  }

  /** Maximum player slots, or null if unknown. */
  maxPlayers(): number | null {
    return this.toInt(this.data.max_players);
  }

  /**
   * Connected player names, normalized to a flat string list regardless of
   * whether the protocol stored rich player objects or bare names.
   */
  playerNames(): string[] {
    const list = this.data.players_list;
    if (!Array.isArray(list)) return [];
    const names: string[] = [];
    for (const player of list) {
      if (typeof player === 'string') names.push(player);
      else if (
        player &&
        typeof player === 'object' &&
        typeof (player as { name?: unknown }).name === 'string'
      ) {
        names.push((player as { name: string }).name);
      }
    }
    return names;
  }

  /**
   * Connected players as structured objects, normalized across protocols —
   * `name` always, plus `score` / `durationSec` where the protocol reports them
   * (A2S, Quake, GameSpy). Use playerNames() if you only want the names.
   */
  playerList(): PlayerInfo[] {
    const list = this.data.players_list;
    if (!Array.isArray(list)) return [];
    const out: PlayerInfo[] = [];
    for (const p of list) {
      if (typeof p === 'string') {
        out.push({ name: p });
        continue;
      }
      if (p && typeof p === 'object') {
        const o = p as Record<string, unknown>;
        const player: PlayerInfo = { name: typeof o.name === 'string' ? o.name : '' };
        if (typeof o.score === 'number') player.score = o.score;
        const dur = o.duration_sec ?? o.duration ?? o.time;
        if (typeof dur === 'number' && Number.isFinite(dur)) player.durationSec = dur;
        out.push(player);
      }
    }
    return out;
  }

  private toInt(v: unknown): number | null {
    if (typeof v === 'number' && Number.isFinite(v)) return v;
    if (typeof v === 'string' && v.trim() !== '' && Number.isFinite(Number(v))) return Number(v);
    return null;
  }

  /** Flat plain-object form (matches the CLI's JSON) — raw data merged over the envelope. */
  toObject(): Record<string, unknown> {
    return {
      id: this.server.id,
      host: this.server.host,
      port: this.server.port,
      protocol: this.server.protocol,
      online: this.online,
      ping_ms: this.pingMs,
      error: this.error,
      error_code: this.errorCode,
      ...this.data,
    };
  }

  /** Alias for toObject(), named to match the PHP port's Result::toArray(). */
  toArray(): Record<string, unknown> {
    return this.toObject();
  }
}
