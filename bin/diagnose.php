<?php

declare(strict_types=1);

/*
 * Diagnose bei HTTP 500 nach dem Deployment. Nur auf der Kommandozeile ausführen:
 *   php bin/diagnose.php
 * Ohne Kommandozeile (Webhosting mit SFTP): dieselben Prüfungen zeigt die Einrichtungsseite /_einrichtung/
 * (docs/deploy-sftp.md). Die Prüflogik steckt in Hvm\Support\Diagnose.
 * Gibt keine Geheimnisse aus, nur ob Werte gesetzt und gültig sind.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$basis = dirname(__DIR__);
$autoload = $basis . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    // Ohne vendor/ laufen die Prüfungen trotzdem, nur der Probeaufruf der Startseite entfällt
    require_once $basis . '/src/Support/Env.php';
    require_once $basis . '/src/Support/Migrator.php';
    require_once $basis . '/src/Support/Diagnose.php';
}

echo "Diagnose Hausverwaltung Müller Webseite\n\n";

$diagnose = (new Hvm\Support\Diagnose($basis))->run();
if (is_file($autoload)) {
    $diagnose->probeStartseite();
}

$marken = [Hvm\Support\Diagnose::OK => '[OK]     ', Hvm\Support\Diagnose::FEHLER => '[FEHLER] ', Hvm\Support\Diagnose::HINWEIS => '[HINWEIS]'];
foreach ($diagnose->ergebnisse() as $e) {
    echo $marken[$e['status']] . ' ' . $e['text'] . ($e['loesung'] === '' ? '' : "\n          Lösung: " . $e['loesung']) . "\n";
}

$fehler = $diagnose->fehlerAnzahl();
echo "\n" . ($fehler === 0 ? 'Keine Fehler gefunden.' : "$fehler Problem(e) gefunden.") . "\n";
exit($fehler === 0 ? 0 : 1);
