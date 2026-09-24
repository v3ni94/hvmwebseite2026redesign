<?php

declare(strict_types=1);

/*
 * Prüft die strukturierten Daten (JSON-LD) und Kern-Metaangaben aller öffentlichen Seiten,
 * Wissensartikel und Stadtseiten ohne laufenden Server über den Kernel (docs/seo-geo.md Abschnitt 3).
 *
 * Aufruf: php bin/check-structured-data.php [--env=staging] [--verbose]
 *   --env=production prüft nur freigegebene Artikel, staging (Standard) mit SHOW_DRAFTS alle Artikel.
 * Prüfungen: JSON parsebar, @context schema.org, Pflichtfelder je Typ, @id-Verweise auflösbar,
 * genau eine h1, Titel höchstens 60 und Beschreibung höchstens 160 Zeichen, canonical und hreflang.
 * Exit-Code 1 bei Fehlern.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Hvm\Http\Kernel;
use Hvm\Http\Request;

$root = dirname(__DIR__);
$env = 'staging';
$verbose = in_array('--verbose', $argv, true);
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--env=')) {
        $env = substr($arg, 6);
    }
}

$kernelEnv = [
    'APP_ENV' => $env,
    'APP_URL' => 'https://www.muellerhv.de',
    'APP_KEY' => 'base64:' . base64_encode(str_repeat('p', 32)),
    'SHOW_DRAFTS' => 'true',
    'SESSION_DRIVER' => 'array',
];

// Pflichtfelder je Typ (Google-Richtlinien für Rich Results bzw. schema.org-Mindestangaben)
$pflicht = [
    'Organization' => ['@id', 'name', 'url', 'address', 'telephone', 'email', 'foundingDate', 'logo'],
    'RealEstateAgent' => ['@id', 'name', 'address', 'telephone'],
    'WebSite' => ['@id', 'name', 'url', 'publisher', 'potentialAction'],
    'WebPage' => ['@id', 'name', 'url', 'isPartOf', 'inLanguage'],
    'AboutPage' => ['@id', 'name', 'url', 'isPartOf', 'about'],
    'ContactPage' => ['@id', 'name', 'url', 'isPartOf'],
    'CollectionPage' => ['@id', 'name', 'url', 'isPartOf'],
    'Service' => ['@id', 'name', 'serviceType', 'provider', 'url', 'areaServed'],
    'Article' => ['headline', 'author', 'publisher', 'datePublished', 'dateModified', 'mainEntityOfPage', 'image'],
    'BreadcrumbList' => ['itemListElement'],
    'FAQPage' => ['mainEntity'],
];

$seiten = require $root . '/config/seiten.php';
$pfade = [];
foreach ($seiten as $meta) {
    if (($meta['sitemap'] ?? false) === true) {
        $pfade[] = $meta['pfad'];
    }
}
foreach (glob($root . '/content/wissen/*.md') ?: [] as $datei) {
    $pfade[] = '/wissen/' . basename($datei, '.md') . '/';
}
// Stadtseiten (config/staedte.php, content/staedte/*.md)
foreach (glob($root . '/content/staedte/*.md') ?: [] as $datei) {
    $pfade[] = '/hausverwaltung-' . basename($datei, '.md') . '/';
}

$fehler = [];
$statistik = ['seiten' => 0, 'bloecke' => 0, 'typen' => []];
foreach ($pfade as $pfad) {
    $response = Kernel::create($root, $kernelEnv)->handle(Request::create('GET', $pfad));
    if ($response->status() === 404 && (str_starts_with($pfad, '/wissen/') || str_starts_with($pfad, '/hausverwaltung-')) && $env === 'production') {
        continue; // Entwurf, in Produktion nicht erreichbar
    }
    if ($response->status() !== 200) {
        $fehler[] = "$pfad: Status " . $response->status();
        continue;
    }
    $statistik['seiten']++;
    $html = $response->body();

    if (preg_match_all('#<h1>[^<]+</h1>#', $html) !== 1 || preg_match_all('#<h1[\s>]#', $html) !== 1) {
        $fehler[] = "$pfad: nicht genau eine h1 ohne Attribute";
    }
    $titel = preg_match('#<title>(.*?)</title>#s', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5) : '';
    $beschreibung = preg_match('#<meta name="description" content="([^"]*)"#', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5) : '';
    if ($titel === '' || mb_strlen($titel) > 60) {
        $fehler[] = "$pfad: Titel fehlt oder länger als 60 Zeichen (" . mb_strlen($titel) . ')';
    }
    if ($beschreibung === '' || mb_strlen($beschreibung) > 160) {
        $fehler[] = "$pfad: Beschreibung fehlt oder länger als 160 Zeichen (" . mb_strlen($beschreibung) . ')';
    }
    foreach (['<link rel="canonical" href="https://www.muellerhv.de' . $pfad . '">', '<link rel="alternate" hreflang="de-DE"', '<meta property="og:image"', '<meta name="twitter:card"'] as $pflichtTag) {
        if (!str_contains($html, $pflichtTag)) {
            $fehler[] = "$pfad: fehlt $pflichtTag";
        }
    }

    preg_match_all('#<script type="application/ld\+json" nonce="[^"]+">(.*?)</script>#s', $html, $bloecke);
    $ids = [];
    $verweise = [];
    $typenSeite = [];
    foreach ($bloecke[1] as $json) {
        $statistik['bloecke']++;
        try {
            $daten = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $fehler[] = "$pfad: JSON-LD nicht parsebar (" . $e->getMessage() . ')';
            continue;
        }
        if (($daten['@context'] ?? null) !== 'https://schema.org') {
            $fehler[] = "$pfad: @context fehlt oder ist nicht https://schema.org";
        }
        $typen = (array) ($daten['@type'] ?? []);
        if ($typen === []) {
            $fehler[] = "$pfad: @type fehlt";
        }
        foreach ($typen as $typ) {
            $typenSeite[] = $typ;
            $statistik['typen'][$typ] = ($statistik['typen'][$typ] ?? 0) + 1;
            foreach ($pflicht[$typ] ?? [] as $feld) {
                if (!isset($daten[$feld]) || $daten[$feld] === '' || $daten[$feld] === []) {
                    $fehler[] = "$pfad: $typ ohne Pflichtfeld $feld";
                }
            }
        }
        if (isset($daten['@id'])) {
            $ids[] = $daten['@id'];
        }
        array_walk_recursive($daten, static function ($wert, $schluessel) use (&$verweise): void {
            if ($schluessel === '@id') {
                $verweise[] = $wert;
            }
        });
        if (in_array('BreadcrumbList', $typen, true)) {
            foreach ($daten['itemListElement'] ?? [] as $i => $eintrag) {
                if (($eintrag['position'] ?? null) !== $i + 1 || empty($eintrag['name']) || !str_starts_with((string) ($eintrag['item'] ?? ''), 'https://')) {
                    $fehler[] = "$pfad: BreadcrumbList-Eintrag " . ($i + 1) . ' unvollständig';
                }
            }
        }
    }
    foreach (array_unique($verweise) as $verweis) {
        // Verweise auf die eigene Seite (mainEntityOfPage) und Seitenanker gelten als auflösbar
        if (!in_array($verweis, $ids, true) && !str_starts_with($verweis, 'https://www.muellerhv.de' . $pfad)) {
            $fehler[] = "$pfad: @id-Verweis $verweis nicht auf der Seite definiert";
        }
    }
    if (!in_array('Organization', $typenSeite, true)) {
        $fehler[] = "$pfad: keine Organisation";
    }
    if ($pfad !== '/' && !in_array('BreadcrumbList', $typenSeite, true)) {
        $fehler[] = "$pfad: keine BreadcrumbList";
    }
    if ($verbose) {
        printf("%-50s %s\n", $pfad, implode(', ', array_unique($typenSeite)));
    }
}

ksort($statistik['typen']);
printf("Geprüft: %d Seiten, %d JSON-LD-Blöcke (Umgebung %s)\n", $statistik['seiten'], $statistik['bloecke'], $env);
foreach ($statistik['typen'] as $typ => $anzahl) {
    printf("  %-16s %d\n", $typ, $anzahl);
}
if ($fehler !== []) {
    fwrite(STDERR, count($fehler) . " Fehler:\n  " . implode("\n  ", $fehler) . "\n");
    exit(1);
}
echo "Keine Fehler.\n";
