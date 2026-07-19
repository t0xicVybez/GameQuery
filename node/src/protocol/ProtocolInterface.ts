import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';

/**
 * A protocol is modeled as a linear conversation: send one packet, look at
 * the reply, decide what (if anything) to send next. This covers single-shot
 * protocols (Minecraft: one request, one reply) and challenge/response ones
 * (A2S, GameSpy 3) uniformly.
 *
 * Protocol instances are stateless — one instance is shared across every
 * server queried concurrently. Per-server state lives in the `history` array
 * threaded through each call. The transport layer owns sockets and timing;
 * the protocol owns byte layout and decision-making.
 */
export interface ProtocolInterface {
  /** 'udp' or 'tcp'. */
  transport(): Transport;

  /** The first step of the conversation. */
  initialStep(server: Server): Step;

  /** Given everything so far, the next step — or null when the conversation is complete. */
  nextStep(server: Server, history: HistoryEntry[]): Step | null;

  /** Turn the full conversation into structured result data. Called once nextStep() returns null. */
  parse(server: Server, history: HistoryEntry[]): Record<string, unknown>;

  /**
   * UDP: always true (one datagram is one complete message). TCP protocols
   * override this to inspect their own length framing and know when a reply
   * spanning multiple reads is whole.
   */
  isResponseComplete(buffer: Buffer): boolean;

  /**
   * Whether this protocol needs the server's host resolved to a numeric IP
   * before initialStep() — true only for protocols that embed the server
   * address in their own request payload (SA-MP). When true, the transport
   * layer resolves the host and exposes it via Server.address().
   * AbstractProtocol returns false.
   */
  requiresAddressResolution(): boolean;

  /**
   * Whether this protocol's UDP reply may span several datagrams and needs
   * reassemble() (A2S). False for every single-datagram protocol, which keeps
   * the fast path unchanged. AbstractProtocol returns false.
   */
  supportsMultiPacket(): boolean;

  /**
   * UDP multi-packet only (supportsMultiPacket() === true): given the datagrams
   * received so far for the current step, return the assembled reply when
   * complete, or null if more are expected.
   */
  reassemble(fragments: Buffer[]): Buffer | null;
}
