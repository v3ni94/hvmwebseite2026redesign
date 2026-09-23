<?php

declare(strict_types=1);

/*
 * Hilfsskript für Hvm\Tests\Integration\AdminAuthTest: genau ein Passwortversuch in einem eigenen
 * Prozess, damit parallele Anmeldeversuche geprüft werden können.
 * Aufruf: php login-versuch.php <email> <passwort> <ip> <startzeit als Unix-Zeit mit Nachkommastellen>
 * Datenbank und APP_KEY kommen aus der Prozessumgebung (IntegrationTestCase::processEnv()).
 */

use Hvm\Http\Kernel;
use Hvm\Security\AdminAuth;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

[, $email, $password, $ip, $start] = $argv + [null, '', '', '', '0'];

$kernel = Kernel::create($root, ['APP_ENV' => 'development', 'SESSION_DRIVER' => 'array']);
$auth = $kernel->container()->get(AdminAuth::class);
// Verbindung vorab aufbauen, damit alle Prozesse möglichst gleichzeitig prüfen
$kernel->container()->get(PDO::class)->query('SELECT 1');

while (microtime(true) < (float) $start) {
    usleep(1000);
}

echo $auth->attemptPassword((string) $email, (string) $password, (string) $ip)['status'], PHP_EOL;
