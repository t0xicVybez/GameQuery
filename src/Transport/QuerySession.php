<?php

declare(strict_types=1);

namespace GameQuery\Transport;

use GameQuery\ErrorCode;
use GameQuery\Protocol\ProtocolInterface;
use GameQuery\Result;
use GameQuery\Server;

/**
 * Owns exactly one server's socket and steps its protocol conversation
 * forward as SocketManager's event loop reports readability/writability.
 * Nothing in here blocks -- every method returns immediately, which is
 * what makes querying hundreds of servers from a single process cheap.
 */
final class QuerySession
{
    /** @var resource|null */
    private $socket = null;

    /** @var list<array{tag: string, request: string, response: string}> */
    private array $history = [];

    private string $currentTag = '';
    private string $currentPacket = '';
    private string $readBuffer = '';

    /**
     * The server passed to the protocol -- identical to $server unless the
     * protocol opts into address resolution, in which case its host has been
     * resolved to a numeric IP (see Server::withResolvedIp).
     */
    private Server $activeServer;

    private bool $connecting = false;
    private bool $done = false;
    private bool $online = false;
    private ?string $error = null;
    private ?string $errorCode = null;

    private float $firstSendTime = 0.0;
    private float $firstResponseTime = 0.0;
    private float $stepStartTime = 0.0;
    private int $retriesLeft;

    public function __construct(
        private readonly Server $server,
        private readonly ProtocolInterface $protocol,
        private readonly float $timeoutSeconds,
        private readonly int $maxRetries,
    ) {
        $this->retriesLeft = $maxRetries;
        $this->activeServer = $server;
    }

    public function open(): void
    {
        // Protocols that embed the server address in their payload need the
        // host resolved to a numeric IP up front. gethostbyname() returns the
        // input unchanged for an IP or on failure, which is the right fallback.
        if ($this->protocol->requiresAddressResolution()) {
            $this->activeServer = $this->server->withResolvedIp(self::resolve($this->server->host));
        }

        try {
            $step = $this->protocol->initialStep($this->activeServer);
        } catch (\Throwable $e) {
            // A protocol can throw here for a config problem it can detect
            // up front (Palworld's missing-password check, for example).
            // That's this one server's fault, not the whole batch's -- fail
            // just this session instead of letting the exception propagate
            // out of SocketManager::run() and take every other server with it.
            $this->finish(online: false, error: $e->getMessage(), errorCode: ErrorCode::CONFIG_ERROR);
            return;
        }

        $transport = $this->protocol->transport();
        $address = sprintf('%s://%s:%d', $transport, $this->server->host, $this->server->port);

        $errno = 0;
        $errstr = '';
        $flags = STREAM_CLIENT_CONNECT;
        if ($transport === 'tcp') {
            $flags |= STREAM_CLIENT_ASYNC_CONNECT;
        }

        $socket = @stream_socket_client($address, $errno, $errstr, $this->timeoutSeconds, $flags);

        if ($socket === false) {
            $this->finish(online: false, error: $errstr !== '' ? $errstr : 'connection failed', errorCode: ErrorCode::UNREACHABLE);
            return;
        }

        stream_set_blocking($socket, false);
        $this->socket = $socket;

        $this->currentTag = $step['tag'];
        $this->currentPacket = $step['packet'];
        $this->firstSendTime = microtime(true);
        $this->stepStartTime = $this->firstSendTime;

        if ($transport === 'udp') {
            $this->send();
        } else {
            // TCP connect is in progress; wait for the socket to become
            // writable before sending anything.
            $this->connecting = true;
        }
    }

    /** @return resource|null */
    public function socket()
    {
        return $this->socket;
    }

    public function isFinished(): bool
    {
        return $this->done;
    }

    public function awaitingConnect(): bool
    {
        return $this->connecting;
    }

    public function handleConnectable(): void
    {
        $this->connecting = false;
        $this->send();
    }

    public function handleReadable(): void
    {
        if ($this->socket === null || !is_resource($this->socket)) {
            return;
        }

        $chunk = @fread($this->socket, 65536);

        if ($chunk === false || ($chunk === '' && feof($this->socket))) {
            // Peer closed the connection. If we already have a usable
            // response buffered, treat it as final; otherwise it's a bust.
            if ($this->readBuffer !== '') {
                $this->recordResponseAndAdvance();
            } else {
                $this->finish(
                    online: !empty($this->history),
                    error: $this->history === [] ? 'connection closed' : null,
                    errorCode: $this->history === [] ? ErrorCode::CONNECTION_CLOSED : null,
                );
            }
            return;
        }

        if ($chunk === '') {
            return; // spurious wakeup, nothing to do yet
        }

        $this->readBuffer .= $chunk;

        if (!$this->protocol->isResponseComplete($this->readBuffer)) {
            return; // TCP: keep accumulating until the framed packet is whole
        }

        $this->recordResponseAndAdvance();
    }

    private function recordResponseAndAdvance(): void
    {
        $now = microtime(true);
        if ($this->firstResponseTime === 0.0) {
            $this->firstResponseTime = $now;
        }

        $this->history[] = [
            'tag' => $this->currentTag,
            'request' => $this->currentPacket,
            'response' => $this->readBuffer,
        ];
        $this->online = true;
        $this->readBuffer = '';

        $next = null;
        try {
            $next = $this->protocol->nextStep($this->activeServer, $this->history);
        } catch (\Throwable $e) {
            $this->finish(online: true, error: $e->getMessage(), errorCode: ErrorCode::PROTOCOL_ERROR);
            return;
        }

        if ($next === null) {
            $this->finish(online: true);
            return;
        }

        $this->currentTag = $next['tag'];
        $this->currentPacket = $next['packet'];
        $this->retriesLeft = $this->maxRetries;
        $this->send();
    }

    private function send(): void
    {
        if ($this->socket === null || !is_resource($this->socket)) {
            return;
        }

        $this->stepStartTime = microtime(true);
        $this->readBuffer = '';

        // Query packets here are all well under typical socket buffer sizes
        // (a few hundred bytes at most), so a single fwrite() reliably
        // covers the whole packet -- no partial-write bookkeeping needed.
        $written = @fwrite($this->socket, $this->currentPacket);

        if ($written === false) {
            $this->finish(
                online: !empty($this->history),
                error: $this->history === [] ? 'write failed' : null,
                errorCode: $this->history === [] ? ErrorCode::UNREACHABLE : null,
            );
        }
    }

    /** Called periodically by SocketManager to enforce per-step timeouts. */
    public function tickTimeout(): void
    {
        if ($this->done) {
            return;
        }

        if (microtime(true) - $this->stepStartTime < $this->timeoutSeconds) {
            return;
        }

        if ($this->retriesLeft > 0) {
            $this->retriesLeft--;
            if ($this->connecting) {
                // Can't usefully "resend" a TCP connect attempt mid-flight;
                // just let it keep waiting once more against a fresh deadline.
                $this->stepStartTime = microtime(true);
            } else {
                $this->send();
            }
            return;
        }

        // Out of retries for this step.
        $this->finish(
            online: !empty($this->history),
            error: $this->history === [] ? 'timeout' : null,
            errorCode: $this->history === [] ? ErrorCode::TIMEOUT : null,
        );
    }

    /** Hard safety-net cutoff invoked if the whole batch has run long past expectations. */
    public function forceTimeout(): void
    {
        if ($this->done) {
            return;
        }

        $this->finish(
            online: !empty($this->history),
            error: $this->history === [] ? 'timeout' : null,
            errorCode: $this->history === [] ? ErrorCode::TIMEOUT : null,
        );
    }

    private function finish(bool $online, ?string $error = null, ?string $errorCode = null): void
    {
        $this->done = true;
        $this->online = $online;
        $this->error = $error;
        $this->errorCode = $errorCode;
    }

    public function close(): void
    {
        if ($this->socket !== null && is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /** @var array<string, array{ip: string, expires: float}> */
    private static array $dnsCache = [];

    /** Resolve a host to an IP, cached briefly to avoid re-querying DNS per poll. */
    private static function resolve(string $host): string
    {
        $now = microtime(true);
        if (isset(self::$dnsCache[$host]) && self::$dnsCache[$host]['expires'] > $now) {
            return self::$dnsCache[$host]['ip'];
        }
        $ip = gethostbyname($host);
        self::$dnsCache[$host] = ['ip' => $ip, 'expires' => $now + 300.0];
        return $ip;
    }

    public function toResult(): Result
    {
        $pingMs = $this->firstResponseTime > 0.0
            ? round(($this->firstResponseTime - $this->firstSendTime) * 1000, 2)
            : 0.0;

        $data = [];
        $error = $this->error;
        $errorCode = $this->errorCode;

        if ($this->online) {
            try {
                $data = $this->protocol->parse($this->activeServer, $this->history);
            } catch (\Throwable $e) {
                // A parse failure is this one server's problem, not the batch's --
                // matches the Node port, which also traps parse() here.
                $error = $e->getMessage();
                $errorCode = ErrorCode::PROTOCOL_ERROR;
            }

            // Surface an authentication rejection (e.g. wrong Palworld password)
            // as a stable code even though a 401 still counts as "reachable".
            if (($data['auth_error'] ?? false) === true && $errorCode === null) {
                $error = 'authentication rejected';
                $errorCode = ErrorCode::AUTH_FAILED;
            }
        }

        return new Result($this->server, $this->online, $pingMs, $data, $error, $errorCode);
    }
}
