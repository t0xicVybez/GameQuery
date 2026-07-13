/** Shared types threaded through the protocol conversation model. */

export type Transport = 'udp' | 'tcp';

/** One step of a protocol conversation: a tagged packet to send. */
export interface Step {
  tag: string;
  packet: Buffer;
}

/** A completed request/response pair, kept so protocols can look back by tag. */
export interface HistoryEntry {
  tag: string;
  request: Buffer;
  response: Buffer;
}
