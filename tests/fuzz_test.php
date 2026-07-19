<?php

declare(strict_types=1);

/*
 * Malformed-input fuzzer -- throws random/garbage buffers at every protocol's
 * parse / nextStep / isResponseComplete / reassemble and asserts none of them
 * crash with an unexpected exception. A remote game server is untrusted input,
 * so a hostile or buggy reply must never take down the process. Run:
 *   php tests/fuzz_test.php
 *
 * The only sanctioned throw is GameQueryException (ByteReader running past the
 * end of the buffer, which the transport catches and reports offline). Any
 * other Throwable -- a TypeError, ValueError, ... -- is a bug to fix.
 */

require __DIR__ . '/../autoload.php';

use GameQuery\Exception\GameQueryException;
use GameQuery\ProtocolRegistry;
use GameQuery\Server;

$registry = new ProtocolRegistry();
$server = new Server('x', '127.0.0.1', 25565, 'fuzz', ['password' => 'pw', 'token' => 'tok', 'voicePort' => 9987]);
$iterations = 4000;

$headers = [
    "\xFF\xFF\xFF\xFF", "\xFF\xFF\xFF\xFE", "\xFE\xFD\x09", "\xFE\xFD\x00",
    "\x00\x00\x00\x01", "\x1c", "\x49", "\x44", "\x45", "\x41", "\x09",
];

$randBytes = static function (int $max): string {
    $len = random_int(0, $max);
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= chr(random_int(0, 255));
    }
    return $out;
};

$fuzzBuffer = static function () use ($randBytes, $headers): string {
    $r = random_int(0, 99);
    if ($r < 15) {
        return '';
    }
    if ($r < 30) {
        return $randBytes(8);
    }
    if ($r < 55) {
        return $headers[array_rand($headers)] . $randBytes(400);
    }
    if ($r < 70) {
        return "HTTP/1.1 200 OK\r\nContent-Length: " . random_int(0, 999) . "\r\n\r\n" . str_repeat('{"a":', random_int(0, 40));
    }
    if ($r < 85) {
        return str_repeat("\x00", random_int(0, 80)) . "\\a\\b\\c\\player_\x00";
    }
    return $randBytes(1600);
};

$unexpected = 0;
$cases = 0;
foreach ($registry->names() as $name) {
    for ($i = 0; $i < $iterations; $i++) {
        $cases++;
        $buf = $fuzzBuffer();
        try {
            $proto = $registry->get($name);
            $proto->isResponseComplete($buf);
            if ($proto->supportsMultiPacket()) {
                $proto->reassemble([$buf]);
                $proto->reassemble([$buf, $fuzzBuffer(), $fuzzBuffer()]);
            }
            $step = $proto->initialStep($server);
            $history = [['tag' => $step['tag'], 'request' => $step['packet'], 'response' => $buf]];
            $guard = 0;
            $next = $proto->nextStep($server, $history);
            while ($next !== null && $guard++ < 8) {
                $history[] = ['tag' => $next['tag'], 'request' => $next['packet'], 'response' => $fuzzBuffer()];
                $next = $proto->nextStep($server, $history);
            }
            $proto->parse($server, $history);
        } catch (GameQueryException) {
            // sanctioned: buffer underrun / config check
        } catch (\Throwable $e) {
            $unexpected++;
            if ($unexpected <= 25) {
                echo 'UNEXPECTED in ' . $name . ': ' . $e::class . ': ' . $e->getMessage() . "\n";
            }
        }
    }
}

echo "\nFuzzed {$cases} cases across " . count($registry->names()) . " protocols\n";
echo $unexpected === 0 ? "Fuzz OK: no unexpected exceptions\n" : "Fuzz FAIL: {$unexpected} unexpected exceptions\n";
exit($unexpected === 0 ? 0 : 1);
