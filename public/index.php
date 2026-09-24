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

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

try {
    if (!is_file($autoload)) {
        throw new RuntimeException('vendor/autoload.php fehlt: composer install --no-dev ausführen.');
    }
    require $autoload;
    $kernel = Hvm\Http\Kernel::fromGlobals(dirname(__DIR__));
} catch (Throwable $e) {
    // Startfehler (fehlende .env, ungültiger APP_KEY, fehlende Abhängigkeiten): Details nur ins Server-Log.
    error_log('HVM Startfehler: ' . get_class($e) . ': ' . $e->getMessage() . ' (Diagnose: php bin/diagnose.php bzw. /_einrichtung/)');
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 300');
    header('X-Robots-Tag: noindex');
    echo '<!doctype html><html lang="de"><meta charset="utf-8"><title>Wartung</title>'
        . '<h1>Die Seite ist vorübergehend nicht erreichbar.</h1>'
        . '<p>Bitte versuchen Sie es in wenigen Minuten erneut. Hausverwaltung Müller GmbH, Telefon 02431 9550300.</p></html>';
    exit;
}

$kernel->handle()->send();
// Nacharbeit nach dem Senden (OUTBOX_MODE=inline: Mail und Webhook ohne Cronjob)
$kernel->terminate();
