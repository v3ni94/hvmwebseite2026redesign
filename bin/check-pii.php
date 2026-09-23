<?php

declare(strict_types=1);

/*
 * Ruft sitemap.xml plus eine feste Zusatzliste (404-Seite, Danke-Seiten aus config/seiten.php) ab und
 * sucht im HTML nach E-Mail-Adressen, deutschen Telefonnummern und typischen Debug-Ausgaben.
 * Treffer, die nicht auf der Whitelist stehen (config/unternehmen.php, config/pii-whitelist.php),
 * führen zu Exitcode 1. Lehre aus der alten Seite (MP Abschnitt 10): Build-Check vor jedem Deployment.
 *
 * Aufruf: php bin/check-pii.php --base-url=https://staging.muellerhv.de [--timeout=10]
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Hvm\Support\Config;

$baseUrl = null;
$timeout = 10;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--base-url=')) {
        $baseUrl = rtrim(substr($arg, strlen('--base-url=')), '/');
    } elseif (str_starts_with($arg, '--timeout=')) {
        $timeout = (int) substr($arg, strlen('--timeout='));
    }
}
if ($baseUrl === null || $baseUrl === '') {
    fwrite(STDERR, "Aufruf: php bin/check-pii.php --base-url=https://staging.muellerhv.de\n");
    exit(2);
}

$config = Config::fromDirectory($root . '/config');

/**
 * Ruft eine URL per curl ab und gibt [Statuscode, Body] zurück. Bricht nicht bei Fehlerstatus ab,
 * damit auch 404- und 500-Seiten geprüft werden.
 *
 * @return array{0: int, 1: string}
 */
function holeSeite(string $url, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['User-Agent: hvm-check-pii/1.0'],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fehler = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        fwrite(STDERR, sprintf("Abruf fehlgeschlagen (%s): %s\n", $url, $fehler));

        return [0, ''];
    }

    return [$status, $body];
}

/**
 * @return list<string> alle Adressen aus der Sitemap (loc-Elemente)
 */
function sitemapAdressen(string $baseUrl, int $timeout): array
{
    [$status, $body] = holeSeite($baseUrl . '/sitemap.xml', $timeout);
    if ($status !== 200 || $body === '') {
        fwrite(STDERR, sprintf("sitemap.xml nicht abrufbar (Status %d) unter %s.\n", $status, $baseUrl));
        exit(2);
    }
    $vorherigerFehler = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    libxml_use_internal_errors($vorherigerFehler);
    if ($xml === false) {
        fwrite(STDERR, "sitemap.xml ist kein gültiges XML.\n");
        exit(2);
    }
    $adressen = [];
    foreach ($xml->url as $url) {
        $adressen[] = $baseUrl . (string) parse_url((string) $url->loc, PHP_URL_PATH);
    }

    return $adressen;
}

/**
 * Feste Zusatzliste: 404-Seite (über einen sicher nicht vorhandenen Pfad) und alle Danke-Seiten
 * aus config/seiten.php (in der Sitemap bewusst nicht enthalten, sollen aber PII-frei bleiben).
 *
 * @return list<string>
 */
function zusatzAdressen(string $baseUrl, Config $config): array
{
    $adressen = [$baseUrl . '/nicht-vorhanden-check-pii-404/'];
    foreach ($config->array('seiten') as $slug => $meta) {
        $slug = (string) $slug;
        if (str_contains($slug, 'danke') || $slug === '404' || $slug === '500') {
            $adressen[] = $baseUrl . (string) ($meta['pfad'] ?? '/');
        }
    }

    return $adressen;
}

/**
 * @return array<string, true> normalisierte Whitelist-Werte (E-Mail klein geschrieben, Telefon nur Ziffern
 *                              mit vorangestelltem "49" statt führender 0, jeweils als Schlüssel)
 */
function whitelist(Config $config): array
{
    $liste = [];
    $firma = $config->array('unternehmen');
    if (isset($firma['email']) && is_string($firma['email'])) {
        $liste['email:' . strtolower($firma['email'])] = true;
    }
    foreach (['telefon', 'notfall_telefon'] as $feld) {
        if (isset($firma[$feld]) && is_string($firma[$feld]) && $firma[$feld] !== '') {
            $liste['tel:' . normalisiereTelefon($firma[$feld])] = true;
        }
    }

    $zusatzDatei = dirname(__DIR__) . '/config/pii-whitelist.php';
    if (is_file($zusatzDatei)) {
        $zusatz = require $zusatzDatei;
        foreach ((array) ($zusatz['emails'] ?? []) as $email) {
            $liste['email:' . strtolower((string) $email)] = true;
        }
        foreach ((array) ($zusatz['telefonnummern'] ?? []) as $nummer) {
            $liste['tel:' . normalisiereTelefon((string) $nummer)] = true;
        }
    }

    return $liste;
}

function normalisiereTelefon(string $nummer): string
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
 * Für Dokumentation reservierte Domains (RFC 2606, RFC 6761) können keiner Person gehören,
 * etwa das Beispiel name@example.org in Hilfetexten der Formulare.
 */
function istBeispielDomain(string $email): bool
{
    $domain = strtolower((string) substr((string) strrchr($email, '@'), 1));

    return (bool) preg_match('/(^|\.)(example\.(org|com|net)|[a-z0-9-]+\.(example|test|invalid))$/', $domain);
}

/**
 * @return list<string> gefundene E-Mail-Adressen (Rohtext)
 */
function findeEmails(string $body): array
{
    preg_match_all('/[a-z0-9.\_%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $body, $treffer);

    return array_values(array_unique($treffer[0]));
}

/**
 * @return list<string> gefundene Telefonnummern (Rohtext), deutsche Schreibweisen:
 *                       +49, 0-Vorwahl, Klammern um die 0, Leerzeichen, Schrägstrich, Bindestrich.
 */
function findeTelefonnummern(string $body): array
{
    $pattern = '/(?<![\d\/])(?:\+49[\s\/\-]?\(0\)[\s\/\-]?\d[\d\s\/\-]{5,12}\d|\+49[\s\/\-]?\d[\d\s\/\-]{5,12}\d|0\d[\d\s\/\-]{5,12}\d)(?![\d])/';
    preg_match_all($pattern, $body, $treffer);
    $ergebnis = [];
    foreach (array_unique($treffer[0]) as $fund) {
        $ziffern = preg_replace('/\D+/', '', $fund) ?? '';
        // Postleitzahlen, Jahreszahlen und andere kurze Ziffernfolgen ausschließen.
        if (strlen($ziffern) < 9 || strlen($ziffern) > 13) {
            continue;
        }
        $ergebnis[] = $fund;
    }

    return $ergebnis;
}

/**
 * @return list<string> gefundene Debug-Marker
 */
function findeDebugAusgaben(string $body): array
{
    $muster = [
        '/Fatal error/i',
        '/Uncaught (Error|Exception|TypeError|ValueError)/i',
        '/Warning:\s*\S/i',
        '/Notice:\s*\S/i',
        '/Deprecated:\s*\S/i',
        '/#\d+\s+\/[\w\-\/.]+\.php\(\d+\)/', // Stacktrace-Zeile
        '/var_dump\(/i',
        '/print_r\(/i',
        '/Records from/i',
        '/Stack trace:/i',
        '/in \/[\w\-\/.]+\.php on line \d+/i',
    ];
    $funde = [];
    foreach ($muster as $regex) {
        if (preg_match($regex, $body, $treffer)) {
            $funde[] = trim($treffer[0]);
        }
    }

    return $funde;
}

function maskiere(string $text): string
{
    $laenge = mb_strlen($text);
    if ($laenge <= 4) {
        return str_repeat('*', $laenge);
    }

    return mb_substr($text, 0, 2) . str_repeat('*', $laenge - 4) . mb_substr($text, -2);
}

$adressen = array_values(array_unique(array_merge(
    sitemapAdressen($baseUrl, $timeout),
    zusatzAdressen($baseUrl, $config)
)));
$liste = whitelist($config);

$befunde = 0;
$geprueft = 0;
foreach ($adressen as $url) {
    [$status, $body] = holeSeite($url, $timeout);
    $geprueft++;
    if ($body === '') {
        continue;
    }

    foreach (findeEmails($body) as $email) {
        if (isset($liste['email:' . strtolower($email)]) || istBeispielDomain($email)) {
            continue;
        }
        $befunde++;
        fwrite(STDOUT, sprintf("%s: E-Mail-Adresse nicht auf der Whitelist: %s\n", $url, maskiere($email)));
    }

    foreach (findeTelefonnummern($body) as $nummer) {
        if (isset($liste['tel:' . normalisiereTelefon($nummer)])) {
            continue;
        }
        $befunde++;
        fwrite(STDOUT, sprintf("%s: Telefonnummer nicht auf der Whitelist: %s\n", $url, maskiere($nummer)));
    }

    foreach (findeDebugAusgaben($body) as $marker) {
        $befunde++;
        fwrite(STDOUT, sprintf("%s: Debug-Ausgabe gefunden: %s\n", $url, maskiere($marker)));
    }
}

fwrite(STDOUT, sprintf("%d Seiten geprüft, %d Befunde.\n", $geprueft, $befunde));
exit($befunde > 0 ? 1 : 0);
