<?php

declare(strict_types=1);

use Hvm\Controller\PageController;
use Hvm\Controller\SeoController;

/*
 * Routen: [Methode, Pfad, [Controller, Methode], Parameter, Optionen].
 * Optionen: 'nicht_in' => Liste von APP_ENV-Werten, in denen die Route nicht registriert wird.
 * Platzhalter {slug} entsprechen [a-z0-9-]+.
 *
 * Vorläufig: /angebot/, /kontakt/, /wissen/, /karriere/bewerbung/ und /admin/ zeigen Platzhalterseiten.
 * Die zuständigen Controller (Angebot, Kontakt, Wissen, Bewerbung, Admin) ersetzen diese Einträge.
 */
$seite = static fn (string $pfad, string $schluessel, array $optionen = []): array
    => ['GET', $pfad, [PageController::class, 'show'], ['page' => $schluessel], $optionen];

// Feature-Routen aus config/routes/*.php werden zuletzt registriert und ersetzen damit gleichlautende Stub-Routen.
$feature = [];
foreach (glob(__DIR__ . '/routes/*.php') ?: [] as $datei) {
    array_push($feature, ...(require $datei));
}

return [
    $seite('/', 'start'),
    $seite('/weg-verwaltung/', 'weg-verwaltung'),
    $seite('/mietverwaltung/', 'mietverwaltung'),
    $seite('/se-verwaltung/', 'se-verwaltung'),
    $seite('/verwalterwechsel/', 'verwalterwechsel'),
    $seite('/vermietung/', 'vermietung'),
    $seite('/verkauf/', 'verkauf'),
    $seite('/wertgutachten/', 'wertgutachten'),
    $seite('/wissen/', 'wissen'),
    $seite('/betreuungsgebiete/', 'betreuungsgebiete'),
    $seite('/referenzen/', 'referenzen'),
    $seite('/ueber-uns/', 'ueber-uns'),
    $seite('/karriere/', 'karriere'),
    $seite('/karriere/bewerbung/', 'karriere-bewerbung'),
    $seite('/service/', 'service'),
    $seite('/notfall/', 'notfall'),
    $seite('/kontakt/', 'kontakt'),
    $seite('/angebot/', 'angebot'),
    $seite('/angebot/danke/', 'angebot-danke'),
    $seite('/impressum/', 'impressum'),
    $seite('/datenschutz/', 'datenschutz'),
    $seite('/barrierefreiheit/', 'barrierefreiheit'),
    $seite('/styleguide/', 'styleguide', ['nicht_in' => ['production']]),
    $seite('/admin/', 'admin'),

    ['GET', '/sitemap.xml', [SeoController::class, 'sitemap']],
    ['GET', '/robots.txt', [SeoController::class, 'robots']],
    ...$feature,
];
