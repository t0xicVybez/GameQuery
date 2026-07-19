import dgram from 'node:dgram';

export interface SteamMasterOptions {
  master?: string;
  masterPort?: number;
  timeoutMs?: number;
  maxServers?: number;
  maxPages?: number;
}

/**
 * Steam master-server query (hl2master) — a "discover" capability that returns a
 * LIST of Source/A2S servers matching a filter, rather than querying one server.
 * Feed the results back into GameQuery.addServer('source', …) to poll each.
 *
 * The master returns servers in batches of ~230; you page by re-querying with
 * the last IP:port as the seed until a 0.0.0.0:0 terminator arrives.
 *
 * Filter uses Valve's backslash syntax, e.g. '\\appid\\730' (CS2) or
 * '\\gamedir\\tf' (TF2). Region 0xFF = all regions.
 */
export class SteamMaster {
  static readonly REGION_ALL = 0xff;
  private static readonly HEADER = Buffer.from([0xff, 0xff, 0xff, 0xff, 0x66, 0x0a]);

  /** Pure: parse one A2M_GET_SERVERS_BATCH2 reply into servers + an end flag. */
  static parseBatch(data: Buffer): { servers: string[]; done: boolean; last: string | null } {
    if (data.length < 6 || !data.subarray(0, 6).equals(SteamMaster.HEADER)) {
      return { servers: [], done: true, last: null };
    }
    const servers: string[] = [];
    let done = false;
    let last: string | null = null;
    for (let o = 6; o + 6 <= data.length; o += 6) {
      const ip = `${data[o]}.${data[o + 1]}.${data[o + 2]}.${data[o + 3]}`;
      const port = ((data[o + 4] as number) << 8) | (data[o + 5] as number);
      if (ip === '0.0.0.0' && port === 0) {
        done = true; // the all-zero entry marks the end of the list
        break;
      }
      const entry = `${ip}:${port}`;
      servers.push(entry);
      last = entry;
    }
    return { servers, done, last };
  }

  /** Query the master server and resolve the de-duplicated list of "ip:port" strings. */
  static async listServers(
    filter = '',
    region: number = SteamMaster.REGION_ALL,
    options: SteamMasterOptions = {},
  ): Promise<string[]> {
    const host = options.master ?? 'hl2master.steampowered.com';
    const port = options.masterPort ?? 27011;
    const timeoutMs = options.timeoutMs ?? 3000;
    const maxServers = options.maxServers ?? 5000;
    const maxPages = options.maxPages ?? 40;

    const sock = dgram.createSocket('udp4');
    const all: string[] = [];
    try {
      await new Promise<void>((resolve, reject) => {
        sock.once('error', reject);
        sock.connect(port, host, () => resolve());
      });
      let seed = '0.0.0.0:0';
      for (let page = 0; page < maxPages; page++) {
        const req = Buffer.concat([
          Buffer.from([0x31, region]),
          Buffer.from(`${seed}\x00${filter}\x00`, 'latin1'),
        ]);
        const data = await SteamMaster.exchange(sock, req, timeoutMs);
        if (data === null) break;
        const batch = SteamMaster.parseBatch(data);
        for (const s of batch.servers) {
          all.push(s);
          if (all.length >= maxServers) return [...new Set(all)];
        }
        if (batch.done || batch.last === null) break;
        seed = batch.last;
      }
    } catch {
      /* return whatever we collected before the failure */
    } finally {
      try {
        sock.close();
      } catch {
        /* already closed */
      }
    }
    return [...new Set(all)];
  }

  private static exchange(sock: dgram.Socket, req: Buffer, timeoutMs: number): Promise<Buffer | null> {
    return new Promise((resolve) => {
      const cleanup = (): void => {
        clearTimeout(timer);
        sock.removeListener('message', onMsg);
      };
      const onMsg = (msg: Buffer): void => {
        cleanup();
        resolve(msg);
      };
      const timer = setTimeout(() => {
        cleanup();
        resolve(null);
      }, timeoutMs);
      sock.on('message', onMsg);
      try {
        sock.send(req, (err) => {
          if (err) {
            cleanup();
            resolve(null);
          }
        });
      } catch {
        cleanup();
        resolve(null);
      }
    });
  }
}
