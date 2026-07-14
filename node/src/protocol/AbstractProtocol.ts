import type { Server } from '../Server.js';
import type { HistoryEntry, Step, Transport } from '../types.js';
import type { ProtocolInterface } from './ProtocolInterface.js';

/**
 * Optional base class — pulls out the bookkeeping every protocol needs
 * (finding a past response by tag) so concrete protocols only have to think
 * about packet layout and sequencing.
 */
export abstract class AbstractProtocol implements ProtocolInterface {
  abstract transport(): Transport;
  abstract initialStep(server: Server): Step;
  abstract nextStep(server: Server, history: HistoryEntry[]): Step | null;
  abstract parse(server: Server, history: HistoryEntry[]): Record<string, unknown>;

  protected responseFor(history: HistoryEntry[], tag: string): Buffer | null {
    for (const entry of history) {
      if (entry.tag === tag) {
        return entry.response;
      }
    }
    return null;
  }

  protected hasTag(history: HistoryEntry[], tag: string): boolean {
    return this.responseFor(history, tag) !== null;
  }

  /** Default: assume every read is a complete message (correct for UDP). */
  isResponseComplete(_buffer: Buffer): boolean {
    return true;
  }

  /**
   * Default: false. Protocols that embed the server's own numeric IP inside
   * their request payload (SA-MP, for one) override this to true, and the
   * transport layer resolves the host to an IP and exposes it via
   * Server.address() before initialStep() runs.
   */
  requiresAddressResolution(): boolean {
    return false;
  }
}
