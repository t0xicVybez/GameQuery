<?php

/**
 * Drop-in autoloader for environments without Composer (e.g. a shared PHP
 * webhost where you just FTP the src/ folder up). If Composer *is*
 * available, prefer requiring vendor/autoload.php instead -- this is a
 * fallback, not a replacement.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'GameQuery\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
