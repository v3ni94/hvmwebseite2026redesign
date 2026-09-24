<?php

declare(strict_types=1);

/*
 * Erzeugt public/assets/search-index.json aus den veröffentlichten Wissensartikeln und FAQ
 * (Titel, Beschreibung, Zielgruppe, URL, bereinigter und gekürzter Text). Genutzt von
 * resources/js/search.js für die Live-Suche auf /wissen/.
 *
 * Aufruf:
 *   php bin/build-search-index.php             nur veröffentlichte Inhalte (Produktion)
 *   php bin/build-search-index.php --drafts    Entwürfe zusätzlich aufnehmen (Staging)
 *   php bin/build-search-index.php --report    Validierungsfehler aus content/wissen und
 *                                              content/faq zusätzlich ausgeben (Exit-Code 1 bei Fehlern)
 */

use Hvm\Content\Article;
use Hvm\Content\FaqRepository;
use Hvm\Content\WissenRepository;
use Hvm\Support\Config;
use Hvm\Support\Env;
use Hvm\Support\Log;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$options = getopt('', ['drafts', 'report']);
$mitEntwuerfen = array_key_exists('drafts', $options);
$mitBericht = array_key_exists('report', $options);

Env::load($root . '/.env');
if ($mitEntwuerfen) {
    Env::set('SHOW_DRAFTS', 'true');
}
date_default_timezone_set('Europe/Berlin');
$config = Config::fromDirectory($root . '/config');
$config->set('app.base_path', $root);
$log = new Log($root . '/storage/logs', 'app.log', 'warning');

$wissen = new WissenRepository($config, $log);
$faq = new FaqRepository($config, $log);

$zielgruppeLabels = [];
foreach (Article::zielgruppen() as $zielgruppe) {
    $zielgruppeLabels[$zielgruppe] = Article::zielgruppeLabel($zielgruppe);
}

/**
 * Entfernt HTML-Tags und überflüssigen Leerraum, kürzt auf eine Höchstlänge.
 */
function bereinigterText(string $html, int $laenge = 600): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));

    return mb_strlen($text) > $laenge ? rtrim(mb_substr($text, 0, $laenge)) . '…' : $text;
}

$eintraege = [];

foreach ($wissen->veroeffentlichte() as $artikel) {
    $eintraege[] = [
        'titel' => $artikel->titel,
        'beschreibung' => $artikel->beschreibung,
        'zielgruppe' => $artikel->zielgruppe,
        'zielgruppeLabel' => $zielgruppeLabels[$artikel->zielgruppe] ?? $artikel->zielgruppe,
        'url' => '/wissen/' . $artikel->slug . '/',
        'text' => bereinigterText($artikel->html),
    ];
}

foreach ($faq->alleGruppen() as $gruppe) {
    foreach ($gruppe->fragen as $frage) {
        $eintraege[] = [
            'titel' => $frage->frage,
            'beschreibung' => bereinigterText($frage->antwort, 160),
            'zielgruppe' => $gruppe->zielgruppe,
            'zielgruppeLabel' => $zielgruppeLabels[$gruppe->zielgruppe] ?? $gruppe->zielgruppe,
            'url' => $frage->artikel !== null ? '/wissen/' . $frage->artikel . '/' : '/wissen/?zielgruppe=' . $gruppe->zielgruppe,
            'text' => bereinigterText($frage->antwort),
        ];
    }
}

$zielpfad = $root . '/public/assets/search-index.json';
if (!is_dir(dirname($zielpfad))) {
    mkdir(dirname($zielpfad), 0775, true);
}
file_put_contents(
    $zielpfad,
    (string) json_encode($eintraege, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
);

\Hvm\Support\Precompress::file($zielpfad);
fwrite(STDOUT, sprintf("Suchindex geschrieben: %s (%d Einträge)\n", $zielpfad, count($eintraege)));

$fehlerGesamt = $wissen->fehlermeldungen() + $faq->fehlermeldungen();
if ($mitBericht) {
    foreach ($fehlerGesamt as $datei => $fehler) {
        fwrite(STDERR, $datei . ":\n");
        foreach ($fehler as $meldung) {
            fwrite(STDERR, '  - ' . $meldung . "\n");
        }
    }
}
if ($fehlerGesamt !== []) {
    fwrite(STDERR, sprintf("%d Datei(en) mit Validierungsfehlern übersprungen (--report für Details).\n", count($fehlerGesamt)));
}

exit($mitBericht && $fehlerGesamt !== [] ? 1 : 0);
