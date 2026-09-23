<?php

declare(strict_types=1);

/*
 * Entfernt personenbezogene Daten aus dem wget-Mirror der Altseite (/legacy), bevor irgendetwas
 * weiterverarbeitet oder committet wird (MP Abschnitt 10 und 13, Phase 1).
 *
 * Entfernt insbesondere:
 * - den Debug-Block „Last 1 Records from Properties Table“ samt Inhalt (siehe
 *   docs/bestandsaufnahme-muellerhv-de.md Abschnitt 0), erkannt an "Records from" bzw.
 *   "Properties Table" und dem umgebenden Markup bis zum naechsten schliessenden Block-Tag.
 * - E-Mail-Adressen ausserhalb der Whitelist (Unternehmensadressen <ort>@muellerhv.de und
 *   info@muellerhv.de duerfen bleiben, siehe config/unternehmen.php und
 *   docs/bestandsaufnahme-muellerhv-de.md Abschnitt 3.7 fuer die Ortskuerzel).
 * - deutsche Telefonnummern ausserhalb der Whitelist (config/unternehmen.php: telefon,
 *   notfall_telefon).
 *
 * Schreibt ein Protokoll (Anzahl Funde je Datei, keine Rohdaten) nach STDOUT und optional in eine Datei.
 * Ersetzt Treffer durch den sichtbaren Platzhalter [entfernt].
 *
 * Aufruf: php bin/legacy-scrub.php --path=/pfad/zum/mirror [--log=/pfad/zum/protokoll.txt]
 * Test mit einer fiktiven HTML-Datei: siehe Abschnitt "Selbsttest" unten.
 */

$root = dirname(__DIR__);

$pfad = null;
$logDatei = null;
$selbsttest = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--path=')) {
        $pfad = rtrim(substr($arg, strlen('--path=')), '/');
    } elseif (str_starts_with($arg, '--log=')) {
        $logDatei = substr($arg, strlen('--log='));
    } elseif ($arg === '--selbsttest') {
        $selbsttest = true;
    }
}

/**
 * Ortskuerzel der Unternehmensadressen laut Bestandsaufnahme Abschnitt 3.7. <ort>@muellerhv.de
 * und info@muellerhv.de sind keine personenbezogenen Daten, sondern Funktionspostfaecher.
 *
 * @return list<string>
 */
function firmenOrtskuerzel(): array
{
    return [
        'berlin', 'muenchen', 'monheim', 'hameln', 'koeln', 'duesseldorf', 'hamburg',
        'hannover', 'frankfurt', 'essen', 'kassel', 'erkelenz', 'konstanz',
    ];
}

/**
 * @return array<string, true> normalisierte Whitelist-Werte (E-Mail klein, Telefon nur Ziffern mit "49")
 */
function scrubWhitelist(string $root): array
{
    $liste = [];
    $configDatei = $root . '/config/unternehmen.php';
    $firma = is_file($configDatei) ? (array) require $configDatei : [];
    if (isset($firma['email']) && is_string($firma['email'])) {
        $liste['email:' . strtolower($firma['email'])] = true;
    }
    foreach (['telefon', 'notfall_telefon'] as $feld) {
        if (isset($firma[$feld]) && is_string($firma[$feld]) && $firma[$feld] !== '') {
            $liste['tel:' . scrubNormalisiereTelefon($firma[$feld])] = true;
        }
    }
    foreach (firmenOrtskuerzel() as $ort) {
        $liste['email:' . $ort . '@muellerhv.de'] = true;
    }
    $liste['email:info@muellerhv.de'] = true;

    return $liste;
}

function scrubNormalisiereTelefon(string $nummer): string
{
    $ziffern = preg_replace('/\D+/', '', $nummer) ?? '';
    if (str_starts_with($ziffern, '0049')) {
        $ziffern = substr($ziffern, 2);
    }
    if (str_starts_with($ziffern, '0')) {
        $ziffern = '49' . substr($ziffern, 1);
    }

    return $ziffern;
}

/**
 * Entfernt den Debug-Block "Last 1 Records from Properties Table" aus dem HTML. Der genaue
 * Rahmen (Tag, Klasse) ist von aussen nicht bekannt, daher ein toleranter Ansatz: von der
 * Fundstelle bis zum naechsten schliessenden Block-Tag auf gleicher oder hoeherer Ebene,
 * ersatzweise bis zum Ende der Zeile, wenn kein Tag gefunden wird.
 *
 * @return array{0: string, 1: int} bereinigter Inhalt, Anzahl entfernter Bloecke
 */
function entferneDebugBlock(string $inhalt): array
{
    $anzahl = 0;
    $pattern = '/(?:<[^>]*>\s*)?Last\s+1\s+Records\s+from\s+Properties\s+Table.*?(<\/(?:div|section|table|pre|p)>)/is';
    $ersetzt = preg_replace_callback($pattern, function () use (&$anzahl) {
        $anzahl++;

        return '[entfernt: Debug-Ausgabe Properties Table]';
    }, $inhalt);
    if ($ersetzt === null) {
        return [$inhalt, 0];
    }

    // Rest-Treffer ohne erkennbaren schliessenden Tag (z. B. am Dateiende) zeilenweise entfernen.
    if (preg_match('/Last\s+1\s+Records\s+from\s+Properties\s+Table/i', $ersetzt)) {
        $zeilen = explode("\n", $ersetzt);
        foreach ($zeilen as $i => $zeile) {
            if (preg_match('/Last\s+1\s+Records\s+from\s+Properties\s+Table/i', $zeile)) {
                $zeilen[$i] = '[entfernt: Debug-Ausgabe Properties Table]';
                $anzahl++;
            }
        }
        $ersetzt = implode("\n", $zeilen);
    }

    return [$ersetzt, $anzahl];
}

/**
 * @param array<string, true> $whitelist
 * @return array{0: string, 1: int} bereinigter Inhalt, Anzahl entfernter E-Mail-Adressen
 */
function entferneEmails(string $inhalt, array $whitelist): array
{
    $anzahl = 0;
    $ersetzt = preg_replace_callback(
        '/[a-z0-9.\_%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i',
        function (array $treffer) use ($whitelist, &$anzahl): string {
            $email = $treffer[0];
            if (isset($whitelist['email:' . strtolower($email)])) {
                return $email;
            }
            $anzahl++;

            return '[entfernt: E-Mail-Adresse]';
        },
        $inhalt
    );

    return [$ersetzt ?? $inhalt, $anzahl];
}

/**
 * @param array<string, true> $whitelist
 * @return array{0: string, 1: int} bereinigter Inhalt, Anzahl entfernter Telefonnummern
 */
function entferneTelefonnummern(string $inhalt, array $whitelist): array
{
    $anzahl = 0;
    $pattern = '/(?<![\d\/])(?:\+49[\s\/\-]?\(0\)[\s\/\-]?\d[\d\s\/\-]{5,12}\d|\+49[\s\/\-]?\d[\d\s\/\-]{5,12}\d|0\d[\d\s\/\-]{5,12}\d)(?![\d])/';
    $ersetzt = preg_replace_callback(
        $pattern,
        function (array $treffer) use ($whitelist, &$anzahl): string {
            $roh = $treffer[0];
            $ziffern = preg_replace('/\D+/', '', $roh) ?? '';
            if (strlen($ziffern) < 9 || strlen($ziffern) > 13) {
                return $roh;
            }
            if (isset($whitelist['tel:' . scrubNormalisiereTelefon($roh)])) {
                return $roh;
            }
            $anzahl++;

            return '[entfernt: Telefonnummer]';
        },
        $inhalt
    );

    return [$ersetzt ?? $inhalt, $anzahl];
}

/**
 * @return array{debug: int, email: int, telefon: int}
 */
function bereinigeDatei(string $datei, array $whitelist): array
{
    $inhalt = file_get_contents($datei);
    if ($inhalt === false) {
        return ['debug' => 0, 'email' => 0, 'telefon' => 0];
    }
    [$inhalt, $debugAnzahl] = entferneDebugBlock($inhalt);
    [$inhalt, $emailAnzahl] = entferneEmails($inhalt, $whitelist);
    [$inhalt, $telefonAnzahl] = entferneTelefonnummern($inhalt, $whitelist);

    if ($debugAnzahl + $emailAnzahl + $telefonAnzahl > 0) {
        file_put_contents($datei, $inhalt);
    }

    return ['debug' => $debugAnzahl, 'email' => $emailAnzahl, 'telefon' => $telefonAnzahl];
}

/**
 * @return list<string>
 */
function htmlDateien(string $pfad): array
{
    $treffer = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pfad, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $datei) {
        /** @var SplFileInfo $datei */
        $endung = strtolower($datei->getExtension());
        if (in_array($endung, ['html', 'htm'], true)) {
            $treffer[] = $datei->getPathname();
        }
    }
    sort($treffer);

    return $treffer;
}

if ($selbsttest) {
    $tmp = sys_get_temp_dir() . '/legacy-scrub-selbsttest-' . bin2hex(random_bytes(4));
    mkdir($tmp);
    $testDatei = $tmp . '/beispiel.html';
    file_put_contents($testDatei, <<<HTML
<!doctype html>
<html><body>
<div class="debug">Last 1 Records from Properties Table
ID: 4711, Contact First Name: Max, Contact Last Name: Mustermann,
Contact Telephone: 0151 23456789, Contact Email: max.mustermann@example.org
</div>
<p>Kontakt: monheim@muellerhv.de, info@muellerhv.de, 02431 9550300</p>
<p>Vertreter: Erika Musterfrau, erika@example.org, 0176 98765432</p>
</body></html>
HTML);
    $whitelist = scrubWhitelist($root);
    $ergebnis = bereinigeDatei($testDatei, $whitelist);
    $inhalt = file_get_contents($testDatei) ?: '';
    $bestehtDebug = !str_contains($inhalt, 'Records from Properties Table');
    $bestehtWhitelist = str_contains($inhalt, 'monheim@muellerhv.de') && str_contains($inhalt, 'info@muellerhv.de') && str_contains($inhalt, '02431 9550300');
    $bestehtEntfernung = !str_contains($inhalt, 'erika@example.org') && !str_contains($inhalt, '0176 98765432') && !str_contains($inhalt, 'max.mustermann@example.org');
    $ok = $bestehtDebug && $bestehtWhitelist && $bestehtEntfernung;
    fwrite(STDOUT, sprintf(
        "Selbsttest: Debug entfernt=%s, Whitelist erhalten=%s, fremde Daten entfernt=%s, Funde=%s\n",
        $bestehtDebug ? 'ja' : 'nein',
        $bestehtWhitelist ? 'ja' : 'nein',
        $bestehtEntfernung ? 'ja' : 'nein',
        json_encode($ergebnis)
    ));
    @unlink($testDatei);
    @rmdir($tmp);
    exit($ok ? 0 : 1);
}

if ($pfad === null || $pfad === '') {
    fwrite(STDERR, "Aufruf: php bin/legacy-scrub.php --path=/pfad/zum/mirror [--log=/pfad/zum/protokoll.txt]\n");
    fwrite(STDERR, "Selbsttest: php bin/legacy-scrub.php --selbsttest\n");
    exit(2);
}
if (!is_dir($pfad)) {
    fwrite(STDERR, sprintf("Verzeichnis nicht gefunden: %s\n", $pfad));
    exit(2);
}

$whitelist = scrubWhitelist($root);
$dateien = htmlDateien($pfad);

$protokollZeilen = [];
$gesamtDebug = 0;
$gesamtEmail = 0;
$gesamtTelefon = 0;
foreach ($dateien as $datei) {
    $ergebnis = bereinigeDatei($datei, $whitelist);
    $gesamtDebug += $ergebnis['debug'];
    $gesamtEmail += $ergebnis['email'];
    $gesamtTelefon += $ergebnis['telefon'];
    if ($ergebnis['debug'] + $ergebnis['email'] + $ergebnis['telefon'] > 0) {
        $relativ = ltrim(substr($datei, strlen($pfad)), '/');
        $protokollZeilen[] = sprintf(
            '%s: Debug-Bloecke=%d, E-Mail-Adressen=%d, Telefonnummern=%d',
            $relativ,
            $ergebnis['debug'],
            $ergebnis['email'],
            $ergebnis['telefon']
        );
    }
}

$kopf = sprintf(
    "Legacy-Bereinigung %s: %d HTML-Dateien geprueft, %d Debug-Bloecke, %d E-Mail-Adressen, %d Telefonnummern entfernt.",
    date('Y-m-d H:i'),
    count($dateien),
    $gesamtDebug,
    $gesamtEmail,
    $gesamtTelefon
);
$ausgabe = $kopf . "\n" . implode("\n", $protokollZeilen) . "\n";

fwrite(STDOUT, $ausgabe);
if ($logDatei !== null && $logDatei !== '') {
    file_put_contents($logDatei, $ausgabe);
}
