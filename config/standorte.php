<?php

declare(strict_types=1);

/*
 * Regionen laut Bestandsaufnahme Abschnitt 3.7 (Altseite /betreuungsgebiete/, Stand 23.09.2026).
 * Erkelenz entfällt (kein Geschäftssitz mehr).
 *
 * status              'eigene_praesenz' | 'partner' | null (null = [eigene Präsenz oder Partnerbetreuung klären])
 * altseite_anschrift  Anschrift, wie sie auf der Altseite stand. Nicht verifiziert, nicht ungeprüft veröffentlichen.
 * verifiziert         true erst nach Bestätigung durch die Geschäftsführung
 * geo                 [lon, lat] des Stadtzentrums, öffentlich bekannte, ungefähre Geodaten (WGS84).
 *                      Keine Objekt- oder Büroadresse, dient nur der groben Verortung auf der Karte.
 * karte               [x, y] dieselbe Position, vorprojiziert (Mercator, siehe
 *                      templates/partials/karte-deutschland.html.twig) auf das Karten-Viewport 0 0 400 520,
 *                      damit Twig ohne eigene Projektionsrechnung auskommt.
 *
 * Telefonnummern und E-Mail-Adressen der Altseite werden bewusst nicht übernommen (fehlerhaft formatiert, uneinheitlich).
 */
return [
    [
        'slug' => 'monheim-am-rhein',
        'name' => 'Monheim am Rhein',
        'status' => 'eigene_praesenz',
        'hauptsitz' => true,
        'anschrift' => 'Rheinpromenade 13, 40789 Monheim am Rhein',
        'verifiziert' => true,
        'quelle' => 'Masterprompt Abschnitt 2 (Hauptsitz)',
        'geo' => [6.8895, 51.0967],
        'karte' => [50.3, 271.5],
    ],
    ['slug' => 'berlin', 'name' => 'Berlin', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Wilmersdorfer Str. 122-123, 10627 Berlin', 'verifiziert' => false, 'geo' => [13.4050, 52.5200], 'karte' => [329.8, 172.8]],
    ['slug' => 'muenchen', 'name' => 'München', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Leopoldstraße 31, 80802 München', 'verifiziert' => false, 'geo' => [11.5820, 48.1351], 'karte' => [251.6, 467.6]],
    ['slug' => 'hameln', 'name' => 'Hameln', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Basbergstraße 96, 31787 Hameln', 'verifiziert' => false, 'geo' => [9.3556, 52.1041], 'karte' => [156.1, 201.9]],
    ['slug' => 'koeln-bonn', 'name' => 'Köln / Bonn', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Dülkenstr. 9, 51143 Köln', 'verifiziert' => false, 'geo' => [6.9603, 50.9375], 'karte' => [53.4, 282.4]],
    ['slug' => 'duesseldorf', 'name' => 'Düsseldorf', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Grafenberger Allee 277-287, 40237 Düsseldorf', 'verifiziert' => false, 'geo' => [6.7735, 51.2277], 'karte' => [45.4, 262.5]],
    ['slug' => 'hamburg', 'name' => 'Hamburg', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Baumwall 7, 20459 Hamburg', 'verifiziert' => false, 'geo' => [9.9937, 53.5511], 'karte' => [183.5, 99.2]],
    ['slug' => 'hannover', 'name' => 'Hannover', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Vahrenwalder Str. 289A, 30179 Hannover', 'verifiziert' => false, 'geo' => [9.7320, 52.3759], 'karte' => [172.3, 182.9]],
    ['slug' => 'frankfurt', 'name' => 'Frankfurt', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Poststraße 2-4, 60329 Frankfurt', 'verifiziert' => false, 'geo' => [8.6821, 50.1109], 'karte' => [127.2, 338.1]],
    ['slug' => 'essen', 'name' => 'Essen', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Rüttenscheider Straße 295, 45131 Essen', 'verifiziert' => false, 'geo' => [7.0116, 51.4556], 'karte' => [55.6, 246.9]],
    ['slug' => 'kassel', 'name' => 'Kassel', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Fünffensterstraße 2, 34117 Kassel', 'verifiziert' => false, 'geo' => [9.4797, 51.3127], 'karte' => [161.4, 256.7]],
    ['slug' => 'konstanz', 'name' => 'Konstanz', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Lohnerhofstraße 2, 78467 Konstanz', 'verifiziert' => false, 'geo' => [9.1770, 47.6603], 'karte' => [148.5, 498.0]],
];
