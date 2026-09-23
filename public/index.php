<?php

declare(strict_types=1);

/*
 * Front Controller. Lokal: php -S 127.0.0.1:8081 -t public public/index.php
 */

if (PHP_SAPI === 'cli-server') {
    // Eingebauter Server: vorhandene statische Dateien direkt ausliefern (keine PHP-Dateien)
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (is_string($path) && $path !== '/' && !str_ends_with(strtolower($path), '.php')) {
        $file = realpath(__DIR__ . rawurldecode($path));
        if ($file !== false && is_file($file) && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)) {
            return false;
        }
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

Hvm\Http\Kernel::fromGlobals(dirname(__DIR__))->handle()->send();
