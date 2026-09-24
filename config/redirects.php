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
$stadt = static fn (string $pfad, string $slug, bool $belegt): array
    => ['von' => $pfad, 'nach' => '/hausverwaltung-' . $slug . '/', 'status' => 301, 'typ' => 'exakt', 'verifiziert' => $belegt];

return [
    ['von' => '/hausverwaltung/', 'nach' => '/weg-verwaltung/', 'status' => 301, 'typ' => 'exakt', 'verifiziert' => true],
    ['von' => '/gutachten/', 'nach' => '/wertgutachten/', 'status' => 301, 'typ' => 'exakt', 'verifiziert' => true],

    // Standortseiten der Altseite auf die Stadtseiten (config/staedte.php), belegt
    $stadt('/monheim/', 'monheim-am-rhein', true),
    $stadt('/muenchen/', 'muenchen', true),
    $stadt('/bernau-bei-berlin/', 'berlin', true),
    $stadt('/konstanz/', 'konstanz', true),
    // Standortseiten, vermutet
    $stadt('/berlin/', 'berlin', false),
    $stadt('/hameln/', 'hannover', false), // PLZ 31787 liegt im Bereich 29000 bis 33999
    $stadt('/koeln/', 'koeln', false),
    $stadt('/koeln-bonn/', 'koeln', false),
    $stadt('/duesseldorf/', 'duesseldorf', false),
    $stadt('/hamburg/', 'hamburg', false),
    $stadt('/hannover/', 'hannover', false),
    $stadt('/frankfurt/', 'frankfurt-am-main', false),
    $stadt('/essen/', 'essen', false),
    $stadt('/kassel/', 'kassel', false),
    $stadt('/erkelenz/', 'erkelenz', false),

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
