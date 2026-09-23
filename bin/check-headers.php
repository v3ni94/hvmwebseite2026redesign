<?php

declare(strict_types=1);

/*
 * Prüft die Security Header aus docs/architektur.md Abschnitt 4 auf allen Adressen der Sitemap
 * (plus / und /robots.txt). Exitcode 1 bei fehlenden oder falschen Headern.
 *
 * Aufruf: php bin/check-headers.php --base-url=https://staging.muellerhv.de
 */

$root = dirname(__DIR__);

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
    fwrite(STDERR, "Aufruf: php bin/check-headers.php --base-url=https://staging.muellerhv.de\n");
    exit(2);
}
$istHttps = str_starts_with($baseUrl, 'https://');

/**
 * @return array{0: int, 1: array<string, string>, 2: string}
 */
function holeMitHeadern(string $url, int $timeout): array
{
    $ch = curl_init($url);
    $header = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $zeile) use (&$header): int {
            $teile = explode(':', $zeile, 2);
            if (count($teile) === 2) {
                $header[strtolower(trim($teile[0]))] = trim($teile[1]);
            }

            return strlen($zeile);
        },
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, $header, $body === false ? '' : $body];
}

/**
 * @return list<string>
 */
function adressen(string $baseUrl, int $timeout): array
{
    [$status, , $body] = holeMitHeadern($baseUrl . '/sitemap.xml', $timeout);
    $adressen = [$baseUrl . '/', $baseUrl . '/robots.txt'];
    if ($status !== 200 || $body === '') {
        fwrite(STDERR, "sitemap.xml nicht abrufbar, prüfe nur / und /robots.txt.\n");

        return $adressen;
    }
    $vorher = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    libxml_use_internal_errors($vorher);
    if ($xml !== false) {
        foreach ($xml->url as $url) {
            $adressen[] = $baseUrl . (string) parse_url((string) $url->loc, PHP_URL_PATH);
        }
    }

    return array_values(array_unique($adressen));
}

/**
 * @param array<string, string> $header
 * @return list<string> Fehlermeldungen, leer wenn alles passt
 */
function pruefeHeader(array $header, bool $istHttps, string $pfad): array
{
    $fehler = [];

    $csp = $header['content-security-policy'] ?? null;
    if ($csp === null) {
        $fehler[] = 'Content-Security-Policy fehlt';
    } else {
        if (str_contains($csp, 'unsafe-inline')) {
            $fehler[] = "Content-Security-Policy enthält 'unsafe-inline'";
        }
        if (!preg_match('/script-src[^;]*\'nonce-[^\']+\'/', $csp)) {
            $fehler[] = 'Content-Security-Policy: script-src ohne Nonce';
        }
        if (!str_contains($csp, "frame-ancestors 'none'")) {
            $fehler[] = "Content-Security-Policy ohne frame-ancestors 'none'";
        }
    }

    if (($header['x-content-type-options'] ?? null) !== 'nosniff') {
        $fehler[] = 'X-Content-Type-Options fehlt oder ist nicht "nosniff"';
    }
    if (!isset($header['referrer-policy'])) {
        $fehler[] = 'Referrer-Policy fehlt';
    }
    if (!isset($header['permissions-policy'])) {
        $fehler[] = 'Permissions-Policy fehlt';
    }
    if (($header['x-frame-options'] ?? null) !== 'DENY') {
        $fehler[] = 'X-Frame-Options fehlt oder ist nicht "DENY"';
    }
    if (($header['cross-origin-opener-policy'] ?? null) !== 'same-origin') {
        $fehler[] = 'Cross-Origin-Opener-Policy fehlt oder ist nicht "same-origin"';
    }
    if ($istHttps && !isset($header['strict-transport-security'])) {
        $fehler[] = 'Strict-Transport-Security fehlt (Produktion, https erwartet)';
    }
    if (str_starts_with($pfad, '/admin/') && !str_contains((string) ($header['x-robots-tag'] ?? ''), 'noindex')) {
        $fehler[] = 'X-Robots-Tag: noindex fehlt unter /admin/';
    }

    return $fehler;
}

$adressen = adressen($baseUrl, $timeout);
$befunde = 0;
foreach ($adressen as $url) {
    [$status, $header] = holeMitHeadern($url, $timeout);
    $pfad = (string) parse_url($url, PHP_URL_PATH);
    foreach (pruefeHeader($header, $istHttps, $pfad) as $fehler) {
        $befunde++;
        fwrite(STDOUT, sprintf("%s (Status %d): %s\n", $url, $status, $fehler));
    }
}

fwrite(STDOUT, sprintf("%d Adressen geprüft, %d Befunde.\n", count($adressen), $befunde));
exit($befunde > 0 ? 1 : 0);
