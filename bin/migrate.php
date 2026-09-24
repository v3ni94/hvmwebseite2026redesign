<?php

declare(strict_types=1);

/*
 * Führt migrations/NNNN_name.sql in Reihenfolge aus und protokolliert sie in schema_migrations.
 * Die Logik steckt in Hvm\Support\Migrator (auch genutzt von der Web-Einrichtung /_einrichtung/).
 *
 * Aufruf:
 *   php bin/migrate.php                  Entwicklungs- bzw. Produktionsdatenbank (DB_*)
 *   php bin/migrate.php --database=test  Testdatenbank (DB_TEST_*)
 *   php bin/migrate.php --status         nur anzeigen, was offen ist
 *   php bin/migrate.php --path=DIR       anderes Migrationsverzeichnis (Tests)
 */

use Hvm\Support\Config;
use Hvm\Support\Db;
use Hvm\Support\Env;
use Hvm\Support\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$options = getopt('', ['database:', 'status', 'path:']);
$connection = ($options['database'] ?? '') === 'test' ? 'db_test' : 'db';
$statusOnly = array_key_exists('status', $options);
$dir = isset($options['path']) ? (string) $options['path'] : $root . '/migrations';

Env::load($root . '/.env');
date_default_timezone_set('UTC');
$config = Config::fromDirectory($root . '/config');

try {
    $pdo = Db::fromConfig($config, $connection);
} catch (Throwable $e) {
    fwrite(STDERR, 'Keine Datenbankverbindung (' . $connection . '): ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$migrator = new Migrator($pdo, $dir);
$pending = $migrator->pending();
if ($pending === []) {
    fwrite(STDOUT, sprintf("Keine offenen Migrationen (%s, %d bereits ausgeführt).\n", $connection, count($migrator->applied())));
    exit(0);
}

if ($statusOnly) {
    foreach ($pending as $version) {
        fwrite(STDOUT, "offen: $version\n");
    }
    exit(0);
}

$result = $migrator->migrate(static function (string $line): void {
    fwrite(STDOUT, $line . PHP_EOL);
});
if (!$result['ok']) {
    fwrite(STDERR, (string) $result['fehler'] . PHP_EOL);
    if ($result['version'] !== null) {
        fwrite(STDERR, "Abbruch. DDL-Anweisungen sind in MariaDB nicht transaktional, bereits ausgeführte Anweisungen dieser Datei prüfen.\n");
    }
    exit(1);
}
exit(0);
