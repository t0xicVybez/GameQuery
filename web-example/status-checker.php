<?php

/**
 * Public-facing status checker page: a form where a visitor picks a
 * protocol, types in host:port (and a password, for Palworld), and gets
 * back the parsed server status.
 *
 * This is meaningfully more than "call GameQuery and print the result" --
 * a public form that makes your server open arbitrary outbound
 * UDP/TCP connections to whatever IP:port a visitor types in is a classic
 * SSRF vector (someone could point it at your own internal network, a
 * cloud metadata endpoint, etc.) and an easy way for your VPS to become
 * an anonymous port-scanning proxy if there's no rate limiting. Both are
 * handled below rather than left as an exercise.
 *
 * Deployment:
 *   1. Drop this file (and the GameQuery library) on your PHP host.
 *   2. Fix the require path below to point at GameQuery's autoload.php
 *      (or vendor/autoload.php if you installed via Composer).
 *   3. Make sure the directory in RATE_LIMIT_DIR is writable by PHP.
 *   4. Serve this over HTTPS -- the password field is a real credential
 *      in transit otherwise. Most hosts handle this for you already; if
 *      yours doesn't, that's the first thing to fix before this goes live.
 */

declare(strict_types=1);

session_start();

require __DIR__ . '/../autoload.php'; // adjust to your actual install path

use GameQuery\Exception\GameQueryException;
use GameQuery\GameQuery;

// ---------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------

/** Only these are selectable -- never let the protocol name come from
 *  raw user input passed straight to ProtocolRegistry, since that widens
 *  what's reachable to whatever you've registered internally. */
const ALLOWED_PROTOCOLS = [
    'source' => 'Source Engine (CS2, TF2, GMod, Space Engineers, SCUM, ...)',
    'source-players' => 'Source Engine + Player List',
    'minecraft' => 'Minecraft: Java Edition',
    'palworld' => 'Palworld (info + players)',
    'palworld-info' => 'Palworld (info only)',
];

const PASSWORD_PROTOCOLS = ['palworld', 'palworld-info'];

const RATE_LIMIT_DIR = __DIR__ . '/../var/rate-limits'; // must be writable by PHP
const RATE_LIMIT_MAX_REQUESTS = 6;
const RATE_LIMIT_WINDOW_SECONDS = 60;

// ---------------------------------------------------------------------
// CSRF token
// ---------------------------------------------------------------------

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ---------------------------------------------------------------------
// Rate limiting -- simple file-based token bucket per client IP.
// Fine for a hobby/community site's traffic level; swap for a DB or
// Redis-backed limiter if this ever sees real load.
// ---------------------------------------------------------------------

function clientIp(): string
{
    // Trust X-Forwarded-For only if you know your host sits behind a
    // reverse proxy that sets it -- otherwise a client can spoof it to
    // dodge the rate limit entirely. Adjust this if you're behind
    // Cloudflare/nginx and know that header is trustworthy in your setup.
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function rateLimitCheck(string $ip): bool
{
    if (!is_dir(RATE_LIMIT_DIR)) {
        @mkdir(RATE_LIMIT_DIR, 0700, true);
    }

    $file = RATE_LIMIT_DIR . '/' . hash('sha256', $ip) . '.json';
    $now = time();

    $timestamps = [];
    if (is_file($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded)) {
            $timestamps = $decoded;
        }
    }

    // Drop anything outside the current window.
    $timestamps = array_values(array_filter(
        $timestamps,
        static fn ($t) => $t > $now - RATE_LIMIT_WINDOW_SECONDS
    ));

    if (count($timestamps) >= RATE_LIMIT_MAX_REQUESTS) {
        return false;
    }

    $timestamps[] = $now;
    @file_put_contents($file, json_encode($timestamps), LOCK_EX);

    return true;
}

// ---------------------------------------------------------------------
// SSRF protection: resolve the hostname ourselves and reject private /
// loopback / reserved ranges before GameQuery ever gets to touch it.
// Also: connect using the resolved IP, not the original hostname, so a
// malicious DNS answer that changes between our check and the actual
// query (DNS rebinding) can't slip a private address through.
// ---------------------------------------------------------------------

function resolveAndValidateHost(string $host): string|false
{
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

    if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
        return false; // gethostbyname() returns the input unchanged on failure
    }

    $isPublic = filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );

    return $isPublic !== false ? $ip : false;
}

// ---------------------------------------------------------------------
// Handle submission
// ---------------------------------------------------------------------

$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Your session expired -- please try again.';
    } elseif (!rateLimitCheck(clientIp())) {
        $error = 'Too many requests. Please wait a minute and try again.';
    } else {
        $protocol = $_POST['protocol'] ?? '';
        $host = trim((string) ($_POST['host'] ?? ''));
        $port = (int) ($_POST['port'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');

        if (!array_key_exists($protocol, ALLOWED_PROTOCOLS)) {
            $error = 'Invalid protocol selected.';
        } elseif ($host === '' || $port < 1 || $port > 65535) {
            $error = 'Please enter a valid host and port (1-65535).';
        } else {
            $resolvedIp = resolveAndValidateHost($host);

            if ($resolvedIp === false) {
                $error = "Couldn't resolve that host, or it points at a private/internal address, which isn't allowed here.";
            } else {
                $options = [];
                if (in_array($protocol, PASSWORD_PROTOCOLS, true)) {
                    if ($password === '') {
                        $error = 'This protocol requires the server admin password.';
                    }
                    $options['password'] = $password;
                }

                if ($error === null) {
                    try {
                        $gq = new GameQuery(timeoutMs: 2500, retries: 1);
                        $gq->addServer($protocol, "{$resolvedIp}:{$port}", options: $options);
                        [$result] = $gq->process();
                    } catch (GameQueryException $e) {
                        // Deliberately generic -- don't echo internal exception
                        // detail (which could include the password on some
                        // error path) back to the page.
                        $error = 'Could not query that server.';
                    }
                }
            }
        }

        // Never keep the password around past this request.
        unset($password, $_POST['password']);
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Game Server Status Checker</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 640px; margin: 3rem auto; padding: 0 1rem; color: #222; }
        label { display: block; margin-top: 1rem; font-weight: 600; }
        input, select { width: 100%; padding: 0.5rem; margin-top: 0.25rem; box-sizing: border-box; }
        button { margin-top: 1.5rem; padding: 0.6rem 1.4rem; cursor: pointer; }
        .error { background: #fdecea; color: #611a15; padding: 0.75rem 1rem; border-radius: 4px; margin-top: 1rem; }
        .result { background: #edf7ed; color: #1e4620; padding: 1rem; border-radius: 4px; margin-top: 1rem; }
        .offline { background: #fdecea; color: #611a15; }
        .field-hint { font-weight: normal; font-size: 0.85em; color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 0.5rem; }
        td { padding: 0.25rem 0.5rem; border-bottom: 1px solid #ddd; }
        td:first-child { font-weight: 600; width: 40%; }
    </style>
</head>
<body>

<h1>Game Server Status Checker</h1>

<?php if ($error !== null): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($result !== null): ?>
    <div class="result <?= $result->online ? '' : 'offline' ?>">
        <strong><?= $result->online ? 'Online' : 'Offline' ?></strong>
        <?php if (!$result->online): ?>
            <p><?= htmlspecialchars($result->error ?? 'No response') ?></p>
        <?php else: ?>
            <table>
                <?php foreach ($result->data as $key => $value): ?>
                    <?php if (is_scalar($value)): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $key) ?></td>
                            <td><?= htmlspecialchars((string) $value) ?></td>
                        </tr>
                    <?php elseif ($key === 'players_list' && is_array($value)): ?>
                        <tr>
                            <td>Players</td>
                            <td><?= htmlspecialchars(implode(', ', array_map('strval', $value)) ?: '(none)') ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <tr><td>Ping</td><td><?= htmlspecialchars((string) $result->pingMs) ?> ms</td></tr>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

    <label for="protocol">Game / Protocol</label>
    <select name="protocol" id="protocol" required onchange="togglePasswordField()">
        <option value="">-- Select --</option>
        <?php foreach (ALLOWED_PROTOCOLS as $value => $label): ?>
            <option value="<?= htmlspecialchars($value) ?>" <?= ($_POST['protocol'] ?? '') === $value ? 'selected' : '' ?>>
                <?= htmlspecialchars($label) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="host">Server IP or hostname</label>
    <input type="text" name="host" id="host" placeholder="203.0.113.10" required
           value="<?= htmlspecialchars($_POST['host'] ?? '') ?>">

    <label for="port">Port</label>
    <input type="number" name="port" id="port" min="1" max="65535" placeholder="27015" required
           value="<?= htmlspecialchars($_POST['port'] ?? '') ?>">

    <label for="password" id="password-label" style="display:none">
        Admin password <span class="field-hint">(Palworld only -- never stored, used for this check only)</span>
    </label>
    <input type="password" name="password" id="password" style="display:none" autocomplete="new-password">

    <button type="submit">Check Status</button>
</form>

<script>
    // Pure convenience -- the field always posts if present; server-side
    // validation is what actually enforces "password required for Palworld".
    function togglePasswordField() {
        const protocol = document.getElementById('protocol').value;
        const needsPassword = ['palworld', 'palworld-info'].includes(protocol);
        document.getElementById('password').style.display = needsPassword ? 'block' : 'none';
        document.getElementById('password-label').style.display = needsPassword ? 'block' : 'none';
    }
    togglePasswordField();
</script>

</body>
</html>
