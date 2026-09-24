<?php

declare(strict_types=1);

/*
 * Betreuungsgebiete: 42 Postleitzahlbereiche laut Absenderadressen-Tabelle der Verwaltungssoftware
 * (Angabe der Geschäftsführung vom 24.09.2026, docs/auftraggeber-angaben.md). Ersetzt config/standorte.php.
 * Je Stadt eine Landingpage /hausverwaltung-<slug>/ mit Text aus content/staedte/<slug>.md.
 *
 * slug          URL-Bestandteil, zugleich Dateiname in content/staedte/
 * name          Stadt, nach der der PLZ-Bereich benannt ist
 * bundesland    Land der Stadt (der PLZ-Bereich kann Landesgrenzen überschreiten)
 * plz_von/bis   PLZ-Bereich als Zeichenkette mit führender Null
 * geo           [lon, lat] des Stadtzentrums, öffentlich bekannte, ungefähre Geodaten (WGS84). Keine Büro- oder
 *               Objektadresse, dient nur der Karte und der Auswahl benachbarter Stadtseiten.
 * bestand_orte  Orte, in denen die HVM bereits Objekte verwaltet (nur Ortsnamen, Angabe der Geschäftsführung)
 * hauptsitz     nur Monheim am Rhein (Sitz laut Handelsregister, config/unternehmen.php)
 * indexierbar   true nur bei echtem lokalem Bezug: Hauptsitz und Städte mit bereits verwalteten Objekten
 *               (bestand_orte). Übrige Stadtseiten: noindex, follow, nicht in sitemap.xml und llms.txt, Canonical
 *               auf sich selbst (docs/seo-geo.md Abschnitt „Stadtseiten und lokale Präsenz“). Eine Stadt wird
 *               indexierbar, sobald dort ein Objekt verwaltet wird (bestand_orte ergänzen, indexierbar true).
 *
 * Bewusst keine Anschriften, Telefonnummern oder Ansprechpartner je Stadt: Ob die Absenderadressen eigene Büros,
 * Partnerbüros oder Postanschriften sind, ist offen (docs/offene-punkte.md A6). Kein LocalBusiness je Stadt.
 */
$stadt = static fn (string $slug, string $name, string $bundesland, string $von, string $bis, float $lon, float $lat, array $bestand = [], bool $hauptsitz = false, bool $indexierbar = false): array => [
    'slug' => $slug,
    'name' => $name,
    'bundesland' => $bundesland,
    'plz_von' => $von,
    'plz_bis' => $bis,
    'geo' => [$lon, $lat],
    'bestand_orte' => $bestand,
    'hauptsitz' => $hauptsitz,
    'indexierbar' => $indexierbar,
];

return [
    $stadt('dresden', 'Dresden', 'Sachsen', '00001', '01999', 13.7373, 51.0504),
    $stadt('cottbus', 'Cottbus', 'Brandenburg', '02000', '03999', 14.3343, 51.7563),
    $stadt('leipzig', 'Leipzig', 'Sachsen', '04000', '06999', 12.3731, 51.3397),
    $stadt('gera', 'Gera', 'Thüringen', '07000', '07999', 12.0823, 50.8779),
    $stadt('chemnitz', 'Chemnitz', 'Sachsen', '08000', '09999', 12.9214, 50.8278),
    $stadt('berlin', 'Berlin', 'Berlin', '10000', '16999', 13.4050, 52.5200, ['Berlin', 'Bernau bei Berlin'], indexierbar: true),
    $stadt('neubrandenburg', 'Neubrandenburg', 'Mecklenburg-Vorpommern', '17000', '17999', 13.2610, 53.5570),
    $stadt('rostock', 'Rostock', 'Mecklenburg-Vorpommern', '18000', '18999', 12.0991, 54.0924),
    $stadt('schwerin', 'Schwerin', 'Mecklenburg-Vorpommern', '19000', '19999', 11.4148, 53.6355),
    $stadt('hamburg', 'Hamburg', 'Hamburg', '20000', '22999', 9.9937, 53.5511),
    $stadt('kiel', 'Kiel', 'Schleswig-Holstein', '23000', '25999', 10.1228, 54.3233),
    $stadt('oldenburg', 'Oldenburg', 'Niedersachsen', '26000', '26999', 8.2146, 53.1435),
    $stadt('bremen', 'Bremen', 'Bremen', '27000', '28999', 8.8017, 53.0793),
    $stadt('hannover', 'Hannover', 'Niedersachsen', '29000', '33999', 9.7320, 52.3759),
    $stadt('kassel', 'Kassel', 'Hessen', '34000', '36999', 9.4797, 51.3127),
    $stadt('braunschweig', 'Braunschweig', 'Niedersachsen', '37000', '38999', 10.5268, 52.2689),
    $stadt('magdeburg', 'Magdeburg', 'Sachsen-Anhalt', '39000', '39999', 11.6276, 52.1205),
    $stadt('duesseldorf', 'Düsseldorf', 'Nordrhein-Westfalen', '40000', '40699', 6.7735, 51.2277),
    $stadt('monheim-am-rhein', 'Monheim am Rhein', 'Nordrhein-Westfalen', '40700', '40999', 6.8895, 51.0967, hauptsitz: true, indexierbar: true),
    $stadt('erkelenz', 'Erkelenz', 'Nordrhein-Westfalen', '41000', '44999', 6.3156, 51.0797, ['Erkelenz', 'Mönchengladbach', 'Remscheid'], indexierbar: true),
    $stadt('essen', 'Essen', 'Nordrhein-Westfalen', '45000', '47999', 7.0116, 51.4556, ['Gelsenkirchen'], indexierbar: true),
    $stadt('muenster', 'Münster', 'Nordrhein-Westfalen', '48000', '49999', 7.6261, 51.9607),
    $stadt('koeln', 'Köln', 'Nordrhein-Westfalen', '50000', '51999', 6.9603, 50.9375, ['Köln'], indexierbar: true),
    $stadt('aachen', 'Aachen', 'Nordrhein-Westfalen', '52000', '52999', 6.0839, 50.7753, ['Düren', 'Stolberg', 'Eschweiler', 'Linnich'], indexierbar: true),
    $stadt('bonn', 'Bonn', 'Nordrhein-Westfalen', '53000', '54999', 7.0982, 50.7374),
    $stadt('koblenz', 'Koblenz', 'Rheinland-Pfalz', '55000', '56999', 7.5890, 50.3569),
    $stadt('siegen', 'Siegen', 'Nordrhein-Westfalen', '57000', '59999', 8.0242, 50.8748),
    $stadt('frankfurt-am-main', 'Frankfurt am Main', 'Hessen', '60000', '64999', 8.6821, 50.1109),
    $stadt('saarbruecken', 'Saarbrücken', 'Saarland', '65000', '66999', 6.9969, 49.2402),
    $stadt('mannheim', 'Mannheim', 'Baden-Württemberg', '67000', '69999', 8.4660, 49.4875),
    $stadt('stuttgart', 'Stuttgart', 'Baden-Württemberg', '70000', '74999', 9.1829, 48.7758),
    $stadt('karlsruhe', 'Karlsruhe', 'Baden-Württemberg', '75000', '76999', 8.4037, 49.0069),
    $stadt('konstanz', 'Konstanz', 'Baden-Württemberg', '77000', '79999', 9.1770, 47.6603),
    $stadt('muenchen', 'München', 'Bayern', '80000', '84999', 11.5820, 48.1351),
    $stadt('augsburg', 'Augsburg', 'Bayern', '85000', '87999', 10.8978, 48.3705),
    $stadt('ulm', 'Ulm', 'Baden-Württemberg', '88000', '89999', 9.9876, 48.4011, ['Friedrichshafen', 'Tettnang'], indexierbar: true),
    $stadt('nuernberg', 'Nürnberg', 'Bayern', '90000', '91999', 11.0767, 49.4521),
    $stadt('regensburg', 'Regensburg', 'Bayern', '92000', '93999', 12.1016, 49.0134),
    $stadt('passau', 'Passau', 'Bayern', '94000', '94999', 13.4319, 48.5667),
    $stadt('wuerzburg', 'Würzburg', 'Bayern', '95000', '97999', 9.9534, 49.7913),
    $stadt('suhl', 'Suhl', 'Thüringen', '98000', '98999', 10.6931, 50.6106),
    $stadt('erfurt', 'Erfurt', 'Thüringen', '99000', '99999', 11.0299, 50.9848),
];
