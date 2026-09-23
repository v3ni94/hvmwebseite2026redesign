<?php

declare(strict_types=1);

/*
 * Findet [ ... ]-Platzhalter in templates/, content/ und config/ (deutsche Kennzeichnungen wie
 * "ergänzen", "festlegen", "bestätigen", "klären", "Freigabe"). Logik in Hvm\Seo\PlaceholderScanner
 * (Unit-Tests: tests/Unit/Seo/PlaceholderScannerTest.php).
 *
 * Aufruf: php bin/check-placeholders.php [--report] [--strict] [--path=...]
 *   --report  schreibt zusätzlich docs/platzhalter-report.md
 *   --strict  Exitcode 1, wenn nicht freigegebene Platzhalter vorhanden sind (Produktions-Deployment)
 *   --path    weiteres Zielverzeichnis oder weitere Zieldatei (wiederholbar), Standard: templates, content, config
 *
 * Whitelist bewusst freigegebener Platzhalter: config/placeholder-approvals.php.
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Hvm\Seo\PlaceholderScanner;

$argvRest = array_slice($argv, 1);
$report = in_array('--report', $argvRest, true);
$strict = in_array('--strict', $argvRest, true);
$ziele = [];
foreach ($argvRest as $arg) {
    if (str_starts_with($arg, '--path=')) {
        $ziele[] = substr($arg, strlen('--path='));
    } elseif (!str_starts_with($arg, '--')) {
        $ziele[] = $arg;
    }
}
if ($ziele === []) {
    $ziele = ['templates', 'content', 'config'];
}

$freigegebenDatei = $root . '/config/placeholder-approvals.php';
$freigegeben = [];
if (is_file($freigegebenDatei)) {
    $geladen = require $freigegebenDatei;
    $freigegeben = is_array($geladen) ? array_map('strval', $geladen) : [];
}

$funde = PlaceholderScanner::scan($ziele, $root, $freigegeben);
$offen = array_values(array_filter($funde, static fn (array $f): bool => !$f['freigegeben']));

foreach ($funde as $fund) {
    $markierung = $fund['freigegeben'] ? ' (freigegeben)' : '';
    fwrite(STDOUT, sprintf("%s:%d: [%s]%s\n", $fund['datei'], $fund['zeile'], $fund['text'], $markierung));
}
fwrite(STDOUT, sprintf(
    "%d Platzhalter gefunden, davon %d offen und %d bewusst freigegeben.\n",
    count($funde),
    count($offen),
    count($funde) - count($offen)
));

if ($report) {
    $zeilen = ['# Platzhalter-Bericht', '', 'Automatisch erzeugt von `bin/check-placeholders.php --report`. Nicht von Hand ändern.', ''];
    $zeilen[] = sprintf('Stand: %s. %d Platzhalter, davon %d offen.', date('d.m.Y'), count($funde), count($offen));
    $zeilen[] = '';
    $zeilen[] = '| Datei | Zeile | Text | Status |';
    $zeilen[] = '|---|---|---|---|';
    foreach ($funde as $fund) {
        $status = $fund['freigegeben'] ? 'freigegeben' : 'offen';
        $zeilen[] = sprintf('| %s | %d | %s | %s |', $fund['datei'], $fund['zeile'], str_replace('|', '\\|', $fund['text']), $status);
    }
    if ($funde === []) {
        $zeilen[] = '| (keine) | (keine) | keine Funde | (keine) |';
    }
    file_put_contents($root . '/docs/platzhalter-report.md', implode("\n", $zeilen) . "\n");
    fwrite(STDOUT, "Bericht geschrieben: docs/platzhalter-report.md\n");
}

if ($strict && $offen !== []) {
    exit(1);
}
exit(0);
