<?php

declare(strict_types=1);

/*
 * Führt migrations/NNNN_name.sql in Reihenfolge aus und protokolliert sie in schema_migrations.
 * Mehrere Anweisungen je Datei sind erlaubt (Trennung per Semikolon, Zeichenketten und Kommentare werden beachtet).
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

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$options = getopt('', ['database:', 'status', 'path:']);
$connection = ($options['database'] ?? '') === 'test' ? 'db_test' : 'db';
$statusOnly = array_key_exists('status', $options);
$dir = isset($options['path']) ? (string) $options['path'] : $root . '/migrations';

Env::load($root . '/.env');
date_default_timezone_set('UTC');
$config = Config::fromDirectory($root . '/config');

/**
 * Zerlegt SQL in einzelne Anweisungen.
 *
 * @return list<string>
 */
function splitSql(string $sql): array
{
    $statements = [];
    $current = '';
    $length = strlen($sql);
    $quote = null;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if ($quote !== null) {
            $current .= $char;
            if ($char === '\\' && $quote !== '`') {
                $current .= $next;
                $i++;
            } elseif ($char === $quote) {
                if ($next === $quote) {
                    $current .= $next;
                    $i++;
                } else {
                    $quote = null;
                }
            }
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $current .= $char;
            continue;
        }
        if (($char === '-' && $next === '-' && in_array($sql[$i + 2] ?? ' ', [' ', "\t", "\n", "\r"], true)) || $char === '#') {
            $end = strpos($sql, "\n", $i);
            $i = $end === false ? $length : $end;
            $current .= "\n";
            continue;
        }
        if ($char === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $length : $end + 1;
            $current .= ' ';
            continue;
        }
        if ($char === ';') {
            if (trim($current) !== '') {
                $statements[] = trim($current);
            }
            $current = '';
            continue;
        }
        $current .= $char;
    }
    if (trim($current) !== '') {
        $statements[] = trim($current);
    }

    return $statements;
}

try {
    $pdo = Db::fromConfig($config, $connection);
} catch (Throwable $e) {
    fwrite(STDERR, 'Keine Datenbankverbindung (' . $connection . '): ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(191) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = is_dir($dir) ? (glob(rtrim($dir, '/') . '/*.sql') ?: []) : [];
sort($files, SORT_STRING);

$pending = array_values(array_filter($files, static fn (string $f): bool => !in_array(basename($f, '.sql'), $applied, true)));
if ($pending === []) {
    fwrite(STDOUT, sprintf("Keine offenen Migrationen (%s, %d bereits ausgeführt).\n", $connection, count($applied)));
    exit(0);
}

foreach ($pending as $file) {
    $version = basename($file, '.sql');
    if ($statusOnly) {
        fwrite(STDOUT, "offen: $version\n");
        continue;
    }
    fwrite(STDOUT, "Migration $version ... ");
    try {
        foreach (splitSql((string) file_get_contents($file)) as $statement) {
            $pdo->exec($statement);
        }
        $insert = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, UTC_TIMESTAMP())');
        $insert->execute([$version]);
    } catch (Throwable $e) {
        fwrite(STDOUT, "Fehler\n");
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        fwrite(STDERR, "Abbruch. DDL-Anweisungen sind in MariaDB nicht transaktional, bereits ausgeführte Anweisungen dieser Datei prüfen.\n");
        exit(1);
    }
    fwrite(STDOUT, "ok\n");
}
exit(0);
