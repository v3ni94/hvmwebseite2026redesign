<?php

declare(strict_types=1);

/*
 * Weiterleitungen von Adressen der Altseite. Dokumentation mit Begründung: docs/redirects.md.
 *
 * von          alter Pfad (Vergleich ohne Groß- und Kleinschreibung, mit oder ohne abschließenden Schrägstrich)
 * nach         neuer Pfad (bei 410 null)
 * status       301 dauerhaft, 302 vorläufig, 410 dauerhaft entfernt
 * typ          exakt | praefix (praefix erfasst auch alle Unterpfade)
 * verifiziert  true = alte URL belegt (Bestandsaufnahme), false = vermutet, per Mirror oder Search Console verifizieren
 */
$standort = static fn (string $pfad, bool $belegt): array
    => ['von' => $pfad, 'nach' => '/betreuungsgebiete/', 'status' => 301, 'typ' => 'exakt', 'verifiziert' => $belegt];

return [
    ['von' => '/hausverwaltung/', 'nach' => '/weg-verwaltung/', 'status' => 301, 'typ' => 'exakt', 'verifiziert' => true],
    ['von' => '/gutachten/', 'nach' => '/wertgutachten/', 'status' => 301, 'typ' => 'exakt', 'verifiziert' => true],

    // Standortseiten, belegt
    $standort('/monheim/', true),
    $standort('/muenchen/', true),
    $standort('/bernau-bei-berlin/', true),
    $standort('/konstanz/', true),
    // Standortseiten, vermutet
    $standort('/berlin/', false),
    $standort('/hameln/', false),
    $standort('/koeln/', false),
    $standort('/koeln-bonn/', false),
    $standort('/duesseldorf/', false),
    $standort('/hamburg/', false),
    $standort('/hannover/', false),
    $standort('/frankfurt/', false),
    $standort('/essen/', false),
    $standort('/kassel/', false),
    $standort('/erkelenz/', false),

    ['von' => '/portfolio/', 'nach' => '/referenzen/', 'status' => 301, 'typ' => 'praefix', 'verifiziert' => true],
    // vorläufig, bis die Datenquelle der Immobilienangebote geklärt ist
    ['von' => '/ff/immobilien/', 'nach' => '/verkauf/', 'status' => 302, 'typ' => 'praefix', 'verifiziert' => true],
    ['von' => '/datenschutzerklaerung/', 'nach' => '/datenschutz/', 'status' => 301, 'typ' => 'exakt', 'verifiziert' => false],

    // WordPress-Endpunkte, dauerhaft entfernt
    ['von' => '/wp-login.php', 'nach' => null, 'status' => 410, 'typ' => 'exakt', 'verifiziert' => true],
    ['von' => '/wp-admin/', 'nach' => null, 'status' => 410, 'typ' => 'praefix', 'verifiziert' => true],
    ['von' => '/xmlrpc.php', 'nach' => null, 'status' => 410, 'typ' => 'exakt', 'verifiziert' => true],
    ['von' => '/feed/', 'nach' => null, 'status' => 410, 'typ' => 'exakt', 'verifiziert' => true],
];
