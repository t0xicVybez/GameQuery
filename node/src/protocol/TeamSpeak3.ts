import { AbstractProtocol } from './AbstractProtocol.js';
import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * TeamSpeak 3 (and TeaSpeak) via the ServerQuery text interface (TCP, default
 * port 10011 — connect to that, not the voice port). ServerQuery is a
 * telnet-style, newline-delimited protocol: the server greets with "TS3", then
 * answers whitespace key=value command replies terminated by an
 * "error id=<n> msg=<...>" line.
 *
 * We select the virtual server by its voice port and read its serverinfo in a
 * single write ("use" then "serverinfo" then "quit"); the server closes after
 * "quit". Pass the voice port as options.voicePort (default 9987).
 *
 * Note: ServerQuery is bound to localhost only on a default install, so remote
 * queries require the admin to have opened query_port externally.
 */
export class TeamSpeak3 extends AbstractProtocol {
  static protocolName(): string {
    return 'teamspeak3';
  }

  transport(): Transport {
    return 'tcp';
  }

  initialStep(server: Server): Step {
    const voicePort = Number(server.options.voicePort ?? 9987);
    return { tag: 'query', packet: Buffer.from(`use port=${voicePort}\nserverinfo\nquit\n`, 'latin1') };
  }

  nextStep(): Step | null {
    return null;
  }

  isResponseComplete(buffer: Buffer): boolean {
    // One "error id=" line per command; serverinfo is done once both the "use"
    // and "serverinfo" replies have landed (the server also closes after
    // "quit", which finalises as a fallback).
    return (buffer.toString('latin1').match(/error id=/g)?.length ?? 0) >= 2;
  }

  parse(_server: Server, history: HistoryEntry[]): Record<string, unknown> {
    const raw = this.responseFor(history, 'query');
    if (raw === null) return {};

    const line = raw
      .toString('utf8')
      .split(/\r?\n/)
      .find((l) => l.includes('virtualserver_name='));
    if (line === undefined) return {};

    const fields: Record<string, string> = {};
    for (const token of line.trim().split(' ')) {
      const eq = token.indexOf('=');
      if (eq === -1) continue;
      fields[token.slice(0, eq)] = this.unescape(token.slice(eq + 1));
    }

    const clients = parseInt(fields.virtualserver_clientsonline ?? '0', 10);
    const queryClients = parseInt(fields.virtualserver_queryclientsonline ?? '0', 10);

    return {
      name: fields.virtualserver_name ?? 'TeamSpeak 3 Server',
      players: Math.max(0, clients - queryClients), // exclude ServerQuery connections
      max_players: parseInt(fields.virtualserver_maxclients ?? '0', 10),
      version: fields.virtualserver_version ?? null,
      players_list: [],
    };
  }

  /** Decode TeamSpeak's backslash escaping (\s = space, \p = pipe, etc.). */
  private unescape(value: string): string {
    const map: Record<string, string> = {
      '\\': '\\',
      '/': '/',
      s: ' ',
      p: '|',
      a: '\x07',
      b: '\x08',
      f: '\x0c',
      n: '\n',
      r: '\r',
      t: '\t',
      v: '\x0b',
    };
    let out = '';
    for (let i = 0; i < value.length; i++) {
      if (value[i] === '\\' && i + 1 < value.length) {
        const next = value[i + 1] as string;
        out += map[next] ?? next;
        i++;
      } else {
        out += value[i];
      }
    }
    return out;
  }
}
