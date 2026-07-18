<?php

declare(strict_types=1);

namespace GameQuery;

/**
 * Stable, machine-readable classification for why a query failed.
 *
 * `Result::$error` is a human-readable message that can change wording between
 * releases; `Result::$errorCode` is one of these constants and is safe to
 * switch on in calling code. Mirrored 1:1 by the Node port's ErrorCode.
 */
final class ErrorCode
{
    /** No response within the timeout (after any retries). */
    public const TIMEOUT = 'TIMEOUT';

    /** Couldn't establish a connection — refused, host/net unreachable, DNS failure. */
    public const UNREACHABLE = 'UNREACHABLE';

    /** The peer closed the connection before a usable response arrived. */
    public const CONNECTION_CLOSED = 'CONNECTION_CLOSED';

    /** The server rejected our credentials (e.g. wrong Palworld admin password). */
    public const AUTH_FAILED = 'AUTH_FAILED';

    /** A response arrived but couldn't be parsed as the expected protocol. */
    public const PROTOCOL_ERROR = 'PROTOCOL_ERROR';

    /** The query was misconfigured up front (e.g. a required option was missing). */
    public const CONFIG_ERROR = 'CONFIG_ERROR';

    private function __construct()
    {
    }
}
