<?php

declare(strict_types=1);

namespace GameQuery\Exception;

use RuntimeException;

/**
 * Thrown for any library-level failure: bad server config, socket errors,
 * or a protocol implementation that can't make sense of a response.
 *
 * Network timeouts are NOT thrown as exceptions -- a server that never
 * answers just comes back with an 'online' => false result. Exceptions
 * are reserved for things that indicate a bug or misconfiguration.
 */
class GameQueryException extends RuntimeException
{
}
