import { QuerySession } from './QuerySession.js';
import type { Result } from '../Result.js';
import type { Server } from '../Server.js';
import type { ProtocolInterface } from '../protocol/ProtocolInterface.js';

export interface Job {
  server: Server;
  protocol: ProtocolInterface;
}

/**
 * Runs every QuerySession concurrently. Because Node sockets are async, this
 * is just Promise.all over the sessions — the event loop interleaves them, so
 * querying 200 servers takes about as long as the single slowest one.
 */
export class SocketManager {
  constructor(
    private readonly timeoutMs: number,
    private readonly maxRetries: number,
  ) {}

  run(jobs: Job[]): Promise<Result[]> {
    const sessions = jobs.map(
      (job) => new QuerySession(job.server, job.protocol, this.timeoutMs, this.maxRetries),
    );
    return Promise.all(sessions.map((s) => s.run()));
  }
}
