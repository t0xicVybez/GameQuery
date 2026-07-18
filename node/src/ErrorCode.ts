/**
 * Stable, machine-readable classification for why a query failed.
 *
 * `Result.error` is a human-readable message that can change wording between
 * releases; `Result.errorCode` is one of these values and is safe to switch on
 * in calling code. Mirrored 1:1 by the PHP port's ErrorCode.
 */
export const ErrorCode = {
  /** No response within the timeout (after any retries). */
  TIMEOUT: 'TIMEOUT',
  /** Couldn't establish a connection — refused, host/net unreachable, DNS failure. */
  UNREACHABLE: 'UNREACHABLE',
  /** The peer closed the connection before a usable response arrived. */
  CONNECTION_CLOSED: 'CONNECTION_CLOSED',
  /** The server rejected our credentials (e.g. wrong Palworld admin password). */
  AUTH_FAILED: 'AUTH_FAILED',
  /** A response arrived but couldn't be parsed as the expected protocol. */
  PROTOCOL_ERROR: 'PROTOCOL_ERROR',
  /** The query was misconfigured up front (e.g. a required option was missing). */
  CONFIG_ERROR: 'CONFIG_ERROR',
} as const;

export type ErrorCodeValue = (typeof ErrorCode)[keyof typeof ErrorCode];
