<?php

declare(strict_types=1);

/*
 * Import der Alttabelle "Properties" in leads (MP 6.3). Export bereitstellen: [Export alte Lead-Tabelle bereitstellen].
 *
 * Aufruf:
 *   php bin/import-legacy-leads.php <datei.csv|datei.sql> [--dry-run] [--table=Properties]
 *
 * - CSV (Trennzeichen ; , oder Tabulator, Kopfzeile mit den Feldnamen der Alttabelle) oder SQL-Dump.
 * - Zuordnung über config/legacy-mapping.php ([Mapping aus Altdatenbank ergänzen]).
 * - Idempotent über legacy_id, --dry-run schreibt nichts.
 * - Protokoll: storage/logs/import-JJJJMMTT-HHMMSS.log, nur Zählwerte, legacy_id und Gründe.
 * Exitcode: 0 ohne Fehler, 3 wenn einzelne Zeilen nicht importiert wurden, 1 bei Abbruch.
 */

use Hvm\Http\Kernel;
use Hvm\Service\LegacyImport;
use Hvm\Support\Clock;
use Hvm\Support\Config;
use Hvm\Support\Log;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$positional = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));
// Optionen selbst auswerten: getopt() bricht beim ersten Positionsargument ab
$dryRun = in_array('--dry-run', $argv, true);
$table = 'Properties';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--table=')) {
        $table = substr($arg, 8);
    }
}
$file = $positional[0] ?? '';

if ($file === '' || !is_file($file) || !is_readable($file)) {
    fwrite(STDERR, "Aufruf: php bin/import-legacy-leads.php <datei.csv|datei.sql> [--dry-run] [--table=Properties]\n");
    exit(2);
}
if (!preg_match('/^[A-Za-z0-9_$]+$/', $table)) {
    fwrite(STDERR, "Ungültiger Tabellenname.\n");
    exit(2);
}

$kernel = Kernel::fromGlobals($root);
$container = $kernel->container();
/** @var Log $log */
$log = $container->get(Log::class);

try {
    $rows = LegacyImport::readFile($file, $table);
    $import = LegacyImport::fromConfig($container->get(PDO::class), $container->get(Config::class));
    $result = $import->import($rows, $dryRun);
} catch (Throwable $e) {
    $reason = $e instanceof InvalidArgumentException ? $e->getMessage() : get_class($e);
    $log->error('Import Altbestand abgebrochen', ['grund' => $reason]);
    fwrite(STDERR, 'Import abgebrochen: ' . $reason . PHP_EOL);
    exit(1);
}

$now = Clock::now();
$protocol = LegacyImport::protocol($result, (string) hash_file('sha256', $file), $dryRun, $now);
$logDir = $root . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$protocolFile = $logDir . '/import-' . $now->format('Ymd-His') . ($dryRun ? '-trockenlauf' : '') . '.log';
file_put_contents($protocolFile, $protocol, LOCK_EX);
@chmod($protocolFile, 0640);

fwrite(STDOUT, ($dryRun ? "Trockenlauf, keine Änderungen.\n" : '') . 'Import Altbestand:' . PHP_EOL);
foreach ($result['zaehler'] as $key => $count) {
    fwrite(STDOUT, sprintf("  %-14s %d\n", $key, $count));
}
fwrite(STDOUT, 'Protokoll: storage/logs/' . basename($protocolFile) . PHP_EOL);
$log->info($dryRun ? 'Import Altbestand (Trockenlauf)' : 'Import Altbestand', $result['zaehler']);

exit($result['zaehler']['fehler'] > 0 ? 3 : 0);
