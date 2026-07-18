import dgram from 'node:dgram';
import net from 'node:net';
import { lookup } from 'node:dns/promises';
import { Result } from '../Result.js';
import { ErrorCode, type ErrorCodeValue } from '../ErrorCode.js';
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
  private errorCode: ErrorCodeValue | null = null;
  private resolveFn: ((r: Result) => void) | null = null;

  /**
   * The server passed to the protocol — identical to `server` unless the
   * protocol opts into address resolution, in which case its host has been
   * resolved to a numeric IP (see Server.withResolvedIp).
   */
  private activeServer: Server;

  constructor(
    private readonly server: Server,
    private readonly protocol: ProtocolInterface,
    private readonly timeoutMs: number,
    private readonly maxRetries: number,
  ) {
    this.retriesLeft = maxRetries;
    this.activeServer = server;
  }

  run(): Promise<Result> {
    return new Promise<Result>((resolve) => {
      this.resolveFn = resolve;
      // Resolution (only for protocols that opt in) is async, so kick off the
      // conversation from a helper rather than blocking the executor.
      void this.begin();
    });
  }

  private async begin(): Promise<void> {
    // Protocols that embed the server address in their payload need the host
    // resolved to a numeric IP before initialStep().
    if (this.protocol.requiresAddressResolution()) {
      try {
        this.activeServer = this.server.withResolvedIp(await QuerySession.resolve(this.server.host));
      } catch {
        // Resolution failed; leave activeServer as host. The query will most
        // likely fail downstream and be reported offline, which is correct.
      }
      if (this.done) return; // a timeout may have fired while we were resolving
    }

    let step;
    try {
      step = this.protocol.initialStep(this.activeServer);
    } catch (err) {
      // A protocol can reject up front (e.g. Palworld's missing password).
      // Fail just this server, not the batch.
      this.finish(false, err instanceof Error ? err.message : String(err), ErrorCode.CONFIG_ERROR);
      return;
    }
    this.currentTag = step.tag;
    this.currentPacket = step.packet;

    if (this.protocol.transport() === 'udp') {
      this.openUdp();
    } else {
      this.openTcp();
    }
  }

  private isUdp(): boolean {
    return this.protocol.transport() === 'udp';
  }

  private openUdp(): void {
    const family = net.isIPv6(this.server.host) ? 'udp6' : 'udp4';
    const sock = dgram.createSocket(family);
    this.socket = sock;
    sock.on('message', (msg) => this.onData(msg));
    sock.on('error', (err) => this.finishSoft(err.message, ErrorCode.UNREACHABLE));
    // Connect the socket to the target so the kernel only delivers datagrams
    // from that exact peer — mirrors the PHP port's connected udp:// socket and
    // stops a stray or spoofed sender from being read as the reply. connect()
    // also resolves the host, so send() below needs no address.
    sock.connect(this.server.port, this.server.host, () => {
      if (this.done) return;
      this.firstSend = this.now();
      this.arm();
      this.sendCurrent();
    });
    // Cover a hung connect/DNS resolve with the same timeout budget.
    this.arm();
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
    sock.on('error', (err) => this.finishSoft(err.message, ErrorCode.UNREACHABLE));
    sock.on('close', () => {
      if (this.done) return;
      if (this.readBuffer.length > 0) {
        this.recordAndAdvance();
      } else {
        this.finishSoft(this.history.length ? null : 'connection closed', ErrorCode.CONNECTION_CLOSED);
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
      next = this.protocol.nextStep(this.activeServer, this.history);
    } catch (err) {
      this.finish(true, err instanceof Error ? err.message : String(err), ErrorCode.PROTOCOL_ERROR);
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
      // The socket is connect()ed, so send() takes no address.
      (this.socket as dgram.Socket).send(this.currentPacket, (err) => {
        if (err) this.finishSoft(this.history.length ? null : 'send failed', ErrorCode.UNREACHABLE);
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
    this.finish(
      this.history.length > 0,
      this.history.length ? null : 'timeout',
      this.history.length ? null : ErrorCode.TIMEOUT,
    );
  }

  private finishSoft(error: string | null, errorCode: ErrorCodeValue): void {
    const offline = this.history.length === 0;
    this.finish(this.history.length > 0, offline ? error : null, offline ? errorCode : null);
  }

  private finish(
    online: boolean,
    error: string | null = null,
    errorCode: ErrorCodeValue | null = null,
  ): void {
    if (this.done) return;
    this.done = true;
    this.online = online;
    this.error = error;
    this.errorCode = errorCode;
    if (this.timer) clearTimeout(this.timer);
    this.closeSocket();

    const pingMs = this.firstResponse > 0 ? Math.round((this.firstResponse - this.firstSend) * 100) / 100 : 0;
    let data: Record<string, unknown> = {};
    if (this.online) {
      try {
        data = this.protocol.parse(this.activeServer, this.history);
      } catch (err) {
        this.error = err instanceof Error ? err.message : String(err);
        this.errorCode = ErrorCode.PROTOCOL_ERROR;
      }
      // Surface an authentication rejection (e.g. wrong Palworld password) as a
      // stable code even though a 401 still counts as "reachable".
      if (data.auth_error === true && this.errorCode === null) {
        this.error = 'authentication rejected';
        this.errorCode = ErrorCode.AUTH_FAILED;
      }
    }
    this.resolveFn?.(new Result(this.server, this.online, pingMs, data, this.error, this.errorCode));
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

  private static readonly dnsCache = new Map<string, { ip: string; expires: number }>();
  private static readonly DNS_TTL_MS = 300_000;

  /** Resolve a host to an IPv4 address, cached briefly to avoid re-querying DNS per poll. */
  private static async resolve(host: string): Promise<string> {
    const now = Date.now();
    const cached = QuerySession.dnsCache.get(host);
    if (cached && cached.expires > now) return cached.ip;
    const { address } = await lookup(host, { family: 4 });
    QuerySession.dnsCache.set(host, { ip: address, expires: now + QuerySession.DNS_TTL_MS });
    return address;
  }
}
