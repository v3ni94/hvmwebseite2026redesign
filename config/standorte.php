<?php

declare(strict_types=1);

/*
 * Regionen laut Bestandsaufnahme Abschnitt 3.7 (Altseite /betreuungsgebiete/, Stand 23.09.2026).
 * Erkelenz entfällt (kein Geschäftssitz mehr).
 *
 * status              'eigene_praesenz' | 'partner' | null (null = [eigene Präsenz oder Partnerbetreuung klären])
 * altseite_anschrift  Anschrift, wie sie auf der Altseite stand. Nicht verifiziert, nicht ungeprüft veröffentlichen.
 * verifiziert         true erst nach Bestätigung durch die Geschäftsführung
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
    ],
    ['slug' => 'berlin', 'name' => 'Berlin', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Wilmersdorfer Str. 122-123, 10627 Berlin', 'verifiziert' => false],
    ['slug' => 'muenchen', 'name' => 'München', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Leopoldstraße 31, 80802 München', 'verifiziert' => false],
    ['slug' => 'hameln', 'name' => 'Hameln', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Basbergstraße 96, 31787 Hameln', 'verifiziert' => false],
    ['slug' => 'koeln-bonn', 'name' => 'Köln / Bonn', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Dülkenstr. 9, 51143 Köln', 'verifiziert' => false],
    ['slug' => 'duesseldorf', 'name' => 'Düsseldorf', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Grafenberger Allee 277-287, 40237 Düsseldorf', 'verifiziert' => false],
    ['slug' => 'hamburg', 'name' => 'Hamburg', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Baumwall 7, 20459 Hamburg', 'verifiziert' => false],
    ['slug' => 'hannover', 'name' => 'Hannover', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Vahrenwalder Str. 289A, 30179 Hannover', 'verifiziert' => false],
    ['slug' => 'frankfurt', 'name' => 'Frankfurt', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Poststraße 2-4, 60329 Frankfurt', 'verifiziert' => false],
    ['slug' => 'essen', 'name' => 'Essen', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Rüttenscheider Straße 295, 45131 Essen', 'verifiziert' => false],
    ['slug' => 'kassel', 'name' => 'Kassel', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Fünffensterstraße 2, 34117 Kassel', 'verifiziert' => false],
    ['slug' => 'konstanz', 'name' => 'Konstanz', 'status' => null, 'hauptsitz' => false, 'altseite_anschrift' => 'Lohnerhofstraße 2, 78467 Konstanz', 'verifiziert' => false],
];
