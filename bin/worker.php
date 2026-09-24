<?php

declare(strict_types=1);

/*
 * Outbox-Worker: versendet Webhooks (n8n) und Mails mit Wiederholung (MP 6.4).
 *
 * Aufruf:
 *   php bin/worker.php               Endlosschleife (Docker-Dienst "worker"), Pause bei leerer Warteschlange
 *   php bin/worker.php --once        ein Durchlauf, z. B. per Cron oder in Tests
 *   php bin/worker.php --limit=50    Einträge je Durchlauf (Standard 20)
 *   php bin/worker.php --sleep=10    Pause in Sekunden bei leerer Warteschlange (Standard 5)
 *
 * STORAGE_MODE=datei: verarbeitet die verschlüsselten Aufträge in storage/outbox (Hvm\Service\FileOutbox).
 *
 * Beenden über SIGTERM oder SIGINT: der laufende Durchlauf wird abgeschlossen.
 * Fehlende Konfiguration (SMTP, LEAD_NOTIFY_TO, N8N_WEBHOOK_URL) führt nicht zum Abbruch:
 * die Einträge bleiben pending, der Log enthält einen Hinweis.
 */

use Hvm\Http\Kernel;
use Hvm\Service\FileOutbox;
use Hvm\Service\OutboxWorker;
use Hvm\Support\Log;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$options = getopt('', ['once', 'limit:', 'sleep:']);
$once = array_key_exists('once', $options);
$limit = max(1, (int) ($options['limit'] ?? 20));
$sleep = max(1, (int) ($options['sleep'] ?? 5));

$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $handler = static function () use (&$stop): void {
        $stop = true;
    };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
}

$build = static function () use ($root): array {
    $kernel = Kernel::fromGlobals($root);
    $container = $kernel->container();

    // STORAGE_MODE=datei: storage/outbox statt Tabelle outbox (gleiche Rückgabe von runOnce)
    $worker = $kernel->config()->get('app.storage_mode', 'datei') === 'datei'
        ? $container->get(FileOutbox::class)
        : $container->get(OutboxWorker::class);

    return [$worker, $container->get(Log::class)];
};

$log = null;
$failures = 0;
do {
    try {
        // Bei jedem Neuaufbau frische Datenbankverbindung (z. B. nach "server has gone away")
        [$worker, $log] = $build();
        while (true) {
            $stats = $worker->runOnce($limit);
            if ($once) {
                fwrite(STDOUT, sprintf(
                    "Outbox: %d beansprucht, %d versendet, %d erneut geplant, %d fehlgeschlagen, %d zurückgestellt\n",
                    $stats['claimed'],
                    $stats['sent'],
                    $stats['retried'],
                    $stats['failed'],
                    $stats['postponed']
                ));
                exit(0);
            }
            $failures = 0;
            if ($stop) {
                break 2;
            }
            if ($stats['claimed'] === 0) {
                for ($i = 0; $i < $sleep && !$stop; $i++) {
                    sleep(1);
                }
            }
            if ($stop) {
                break 2;
            }
        }
    } catch (Throwable $e) {
        $failures++;
        $message = sprintf('Worker-Fehler (%s), neuer Versuch', get_class($e));
        if ($log instanceof Log) {
            $log->error($message, ['code' => (string) $e->getCode()]);
        }
        fwrite(STDERR, $message . PHP_EOL);
        if ($once) {
            exit(1);
        }
        // Rückfall bei wiederholten Fehlern (z. B. Datenbank nicht erreichbar), höchstens eine Minute
        sleep(min(60, $sleep * $failures));
    }
} while (!$stop);

exit(0);
