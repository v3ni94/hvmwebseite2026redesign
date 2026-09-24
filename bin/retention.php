<?php

declare(strict_types=1);

/*
 * Löschlauf nach Löschkonzept (docs/loeschkonzept.md, MP 6.5).
 *
 * Aufruf:
 *   php bin/retention.php             Löschen bzw. Anonymisieren nach LEAD_RETENTION_DAYS
 *   php bin/retention.php --dry-run   nur zählen, nichts ändern
 *
 * STORAGE_MODE=datei: löscht fehlgeschlagene Outbox-Aufträge nach LEAD_RETENTION_DAYS, spätestens nach 30 Tagen
 * (läuft auch ohne gesetzte Frist, zusätzlich stündlich inline nach Formularen).
 * Ohne LEAD_RETENTION_DAYS ([Aufbewahrungsfrist festlegen]) ist der Löschlauf deaktiviert: Hinweis, Exitcode 0.
 * Protokoll: storage/logs/retention.log, nur Zählwerte, keine personenbezogenen Daten.
 * Empfohlen: täglich per Cron oder als Docker-Job, zuerst mit --dry-run prüfen.
 */

use Hvm\Http\Kernel;
use Hvm\Service\FileOutbox;
use Hvm\Service\Retention;
use Hvm\Support\Log;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$options = getopt('', ['dry-run']);
$dryRun = array_key_exists('dry-run', $options);

$kernel = Kernel::fromGlobals($root);
$container = $kernel->container();
$protocol = new Log($root . '/storage/logs', 'retention.log', 'info');

// STORAGE_MODE=datei: keine Datenbank, nur fehlgeschlagene Outbox-Aufträge (Frist LEAD_RETENTION_DAYS,
// höchstens 30 Tage), verwaiste Bewerbungsdateien und temporäre Dateien
if ($kernel->config()->get('app.storage_mode', 'datei') === 'datei') {
    try {
        /** @var FileOutbox $files */
        $files = $container->get(FileOutbox::class);
        $ergebnis = $files->cleanup($dryRun);
    } catch (Throwable $e) {
        $container->get(Log::class)->error('Löschlauf (Dateimodus) fehlgeschlagen', ['fehler' => get_class($e)]);
        fwrite(STDERR, 'Löschlauf fehlgeschlagen: ' . get_class($e) . PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT, sprintf("Löschlauf Dateimodus%s: Frist %d Tage\n", $dryRun ? ' (Trockenlauf, keine Änderungen)' : '', $files->retentionDays()));
    foreach ($ergebnis as $key => $count) {
        fwrite(STDOUT, sprintf("  %-30s %d\n", $key, $count));
    }
    $protocol->info($dryRun ? 'Löschlauf Dateimodus (Trockenlauf)' : 'Löschlauf Dateimodus', ['tage' => $files->retentionDays()] + $ergebnis);
    exit(0);
}

try {
    /** @var Retention $retention */
    $retention = $container->get(Retention::class);
    $days = $retention->configuredDays();
    if ($days === null) {
        $message = 'Löschlauf deaktiviert: LEAD_RETENTION_DAYS ist nicht gesetzt [Aufbewahrungsfrist festlegen].';
        fwrite(STDOUT, $message . PHP_EOL);
        $protocol->info($message);
        exit(0);
    }
    $result = $retention->run($days, $dryRun);
} catch (Throwable $e) {
    $container->get(Log::class)->error('Löschlauf fehlgeschlagen', ['fehler' => get_class($e)]);
    $protocol->error('Löschlauf fehlgeschlagen', ['fehler' => get_class($e)]);
    fwrite(STDERR, 'Löschlauf fehlgeschlagen: ' . get_class($e) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Löschlauf%s: Frist %d Tage, Stichtag %s (UTC)\n",
    $dryRun ? ' (Trockenlauf, keine Änderungen)' : '',
    $result['tage'],
    $result['stichtag']
));
foreach ($result['ergebnisse'] as $key => $count) {
    fwrite(STDOUT, sprintf("  %-30s %d\n", $key, $count));
}
$protocol->info($dryRun ? 'Löschlauf (Trockenlauf)' : 'Löschlauf', ['tage' => $result['tage']] + $result['ergebnisse']);
exit(($result['ergebnisse']['dateien_fehler'] ?? 0) > 0 ? 3 : 0);
