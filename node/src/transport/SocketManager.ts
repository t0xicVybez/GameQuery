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

  /** Like run(), but yields each Result the moment it resolves (completion order, not add order). */
  async *runStream(jobs: Job[]): AsyncGenerator<Result> {
    const sessions = jobs.map(
      (job) => new QuerySession(job.server, job.protocol, this.timeoutMs, this.maxRetries),
    );
    const queue: Result[] = [];
    let notify: (() => void) | null = null;
    let remaining = sessions.length;
    for (const s of sessions) {
      void s.run().then((r) => {
        queue.push(r);
        remaining -= 1;
        notify?.();
      });
    }
    while (remaining > 0 || queue.length > 0) {
      if (queue.length === 0) {
        await new Promise<void>((resolve) => {
          notify = resolve;
        });
        notify = null;
      }
      while (queue.length > 0) yield queue.shift() as Result;
    }
  }
}
