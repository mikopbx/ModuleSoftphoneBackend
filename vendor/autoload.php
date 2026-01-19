<?php

/**
 * Minimal autoloader for ModuleSoftphoneBackend.
 *
 * This module only needs Cesargb\Log\* classes from:
 * vendor/cesargb/php-log-rotation/src
 *
 * NOTE: Other dependencies (e.g. Phalcon, Guzzle) are provided by the MikoPBX runtime.
 */

declare(strict_types=1);

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'Cesargb\\Log\\';
        // PHP 7.4 compatibility: avoid str_starts_with() (PHP 8+).
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $baseDir = __DIR__ . '/cesargb/php-log-rotation/src/';
        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    },
    true,
    true
);

return true;
