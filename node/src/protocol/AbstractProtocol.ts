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

  /**
   * Default: false. A protocol whose UDP reply can span several datagrams (A2S,
   * for one) overrides this to true; the transport then hands every datagram of
   * the current step to reassemble() instead of treating the first one as the
   * whole reply. Leaving it false keeps the single-datagram fast path untouched
   * for every other UDP protocol.
   */
  supportsMultiPacket(): boolean {
    return false;
  }

  /**
   * Only called when supportsMultiPacket() is true. Given every datagram
   * received for the current step so far, return the assembled reply once it is
   * complete, or null if more datagrams are still expected. The default just
   * returns the latest datagram (no reassembly).
   */
  reassemble(fragments: Buffer[]): Buffer | null {
    return fragments.length === 0 ? null : (fragments[fragments.length - 1] as Buffer);
  }
}
