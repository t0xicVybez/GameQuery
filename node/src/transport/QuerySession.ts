import dgram from 'node:dgram';
import net from 'node:net';
import { Result } from '../Result.js';
import type { Server } from '../Server.js';
import type { ProtocolInterface } from '../protocol/ProtocolInterface.js';
import type { HistoryEntry } from '../types.js';

/**
 * Drives one server's protocol conversation to completion, resolving a Result.
 *
 * Node's dgram/net sockets are already event-driven, so — unlike the PHP port
 * with its stream_select() loop — concurrency is free: SocketManager simply
 * runs every session's promise at once and the event loop interleaves them.
 * Nothing here blocks.
 */
export class QuerySession {
  private history: HistoryEntry[] = [];
  private socket: dgram.Socket | net.Socket | null = null;
  private currentTag = '';
  private currentPacket: Buffer = Buffer.alloc(0);
  private readBuffer: Buffer = Buffer.alloc(0);

  private firstSend = 0;
  private firstResponse = 0;
  private retriesLeft: number;
  private timer: NodeJS.Timeout | null = null;

  private done = false;
  private online = false;
  private error: string | null = null;
  private resolveFn: ((r: Result) => void) | null = null;

  constructor(
    private readonly server: Server,
    private readonly protocol: ProtocolInterface,
    private readonly timeoutMs: number,
    private readonly maxRetries: number,
  ) {
    this.retriesLeft = maxRetries;
  }

  run(): Promise<Result> {
    return new Promise<Result>((resolve) => {
      this.resolveFn = resolve;

      let step;
      try {
        step = this.protocol.initialStep(this.server);
      } catch (err) {
        // A protocol can reject up front (e.g. Palworld's missing password).
        // Fail just this server, not the batch.
        this.finish(false, err instanceof Error ? err.message : String(err));
        return;
      }
      this.currentTag = step.tag;
      this.currentPacket = step.packet;

      if (this.protocol.transport() === 'udp') {
        this.openUdp();
      } else {
        this.openTcp();
      }
    });
  }

  private isUdp(): boolean {
    return this.protocol.transport() === 'udp';
  }

  private openUdp(): void {
    const family = net.isIPv6(this.server.host) ? 'udp6' : 'udp4';
    const sock = dgram.createSocket(family);
    this.socket = sock;
    sock.on('message', (msg) => this.onData(msg));
    sock.on('error', (err) => this.finishSoft(err.message));
    this.firstSend = this.now();
    this.arm();
    this.sendCurrent();
  }

  private openTcp(): void {
    const sock = net.connect({ host: this.server.host, port: this.server.port });
    sock.setNoDelay(true);
    this.socket = sock;
    sock.on('connect', () => {
      this.firstSend = this.now();
      this.arm();
      this.sendCurrent();
    });
    sock.on('data', (chunk) => this.onData(chunk));
    sock.on('error', (err) => this.finishSoft(err.message));
    sock.on('close', () => {
      if (this.done) return;
      if (this.readBuffer.length > 0) {
        this.recordAndAdvance();
      } else {
        this.finishSoft(this.history.length ? null : 'connection closed');
      }
    });
    // Connect timeout is covered by arm() below; firstSend is re-set on 'connect'.
    this.arm();
  }

  private onData(chunk: Buffer): void {
    if (this.done) return;
    this.readBuffer = this.isUdp() ? chunk : Buffer.concat([this.readBuffer, chunk]);
    if (!this.protocol.isResponseComplete(this.readBuffer)) {
      return; // TCP: keep accumulating until the framed packet is whole
    }
    this.recordAndAdvance();
  }

  private recordAndAdvance(): void {
    if (this.firstResponse === 0) {
      this.firstResponse = this.now();
    }
    this.history.push({ tag: this.currentTag, request: this.currentPacket, response: this.readBuffer });
    this.online = true;
    this.readBuffer = Buffer.alloc(0);

    let next;
    try {
      next = this.protocol.nextStep(this.server, this.history);
    } catch (err) {
      this.finish(true, err instanceof Error ? err.message : String(err));
      return;
    }

    if (next === null) {
      this.finish(true);
      return;
    }

    this.currentTag = next.tag;
    this.currentPacket = next.packet;
    this.retriesLeft = this.maxRetries;
    this.arm();
    this.sendCurrent();
  }

  private sendCurrent(): void {
    if (this.socket === null) return;
    this.readBuffer = Buffer.alloc(0);
    if (this.isUdp()) {
      (this.socket as dgram.Socket).send(this.currentPacket, this.server.port, this.server.host, (err) => {
        if (err) this.finishSoft(this.history.length ? null : 'send failed');
      });
    } else {
      (this.socket as net.Socket).write(this.currentPacket);
    }
  }

  private arm(): void {
    if (this.timer) clearTimeout(this.timer);
    this.timer = setTimeout(() => this.onTimeout(), this.timeoutMs);
  }

  private onTimeout(): void {
    if (this.done) return;
    if (this.retriesLeft > 0) {
      this.retriesLeft--;
      this.arm();
      // For a TCP connect still in flight, just wait once more; otherwise resend.
      if (this.isUdp() || (this.socket instanceof net.Socket && !this.socket.connecting)) {
        this.sendCurrent();
      }
      return;
    }
    this.finish(this.history.length > 0, this.history.length ? null : 'timeout');
  }

  private finishSoft(error: string | null): void {
    this.finish(this.history.length > 0, this.history.length ? null : error);
  }

  private finish(online: boolean, error: string | null = null): void {
    if (this.done) return;
    this.done = true;
    this.online = online;
    this.error = error;
    if (this.timer) clearTimeout(this.timer);
    this.closeSocket();

    const pingMs = this.firstResponse > 0 ? Math.round((this.firstResponse - this.firstSend) * 100) / 100 : 0;
    let data: Record<string, unknown> = {};
    if (this.online) {
      try {
        data = this.protocol.parse(this.server, this.history);
      } catch (err) {
        this.error = err instanceof Error ? err.message : String(err);
      }
    }
    this.resolveFn?.(new Result(this.server, this.online, pingMs, data, this.error));
  }

  private closeSocket(): void {
    if (this.socket === null) return;
    try {
      if (this.socket instanceof dgram.Socket) {
        this.socket.close();
      } else {
        this.socket.destroy();
      }
    } catch {
      /* already closed */
    }
    this.socket = null;
  }

  private now(): number {
    return Number(process.hrtime.bigint() / 1000n) / 1000; // ms with sub-ms resolution
  }
}
