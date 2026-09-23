<?php

declare(strict_types=1);

/*
 * Stammdaten der Hausverwaltung Müller GmbH, verbindlich laut Masterprompt Abschnitt 2.
 * null bedeutet: Angabe fehlt, im Template Platzhalter anzeigen, niemals erfinden.
 */
return [
    'name' => 'Hausverwaltung Müller GmbH',
    'kurzname' => 'HVM',
    'anschrift' => [
        'strasse' => 'Rheinpromenade 13',
        'plz' => '40789',
        'ort' => 'Monheim am Rhein',
        'land' => 'DE',
    ],
    'registergericht' => 'Amtsgericht Düsseldorf',
    'hrb' => 'HRB 104762',
    'geschaeftsfuehrer' => 'Timo Müller',
    'gruendung' => '2020-03-04',
    'gruendung_text' => 'seit 2020',
    'email' => 'info@muellerhv.de',

    'telefon' => '02431 9550300', // bestätigt durch GF am 23.09.2026
    'telefon_platzhalter' => 'zentrale Telefonnummer bestätigen',
    // [Notfallnummer bestätigen]
    'notfall_telefon' => null,
    'notfall_telefon_platzhalter' => 'Notfallnummer bestätigen',
    // [USt-IdNr. ergänzen, falls vorhanden]. Niemals eine Steuernummer veröffentlichen.
    'ust_id' => null,
    'ust_id_platzhalter' => 'USt-IdNr. ergänzen, falls vorhanden',
    // [Portal-Adresse bestätigen]
    'portal_url' => null,
    'portal_url_platzhalter' => 'Portal-Adresse bestätigen',

    'mitgliedschaften' => [
        ['kurz' => 'VZIV', 'name' => 'Verein Zertifizierter ImmobilienVerwalter e. V.'],
        ['kurz' => 'IVD', 'name' => 'IVD'],
    ],

    'leistungen' => [
        ['schluessel' => 'weg', 'name' => 'WEG-Verwaltung', 'url' => '/weg-verwaltung/'],
        ['schluessel' => 'miet', 'name' => 'Mietverwaltung', 'url' => '/mietverwaltung/'],
        ['schluessel' => 'se', 'name' => 'Sondereigentumsverwaltung (SE-Verwaltung)', 'url' => '/se-verwaltung/'],
        ['schluessel' => 'vermietung', 'name' => 'Vermietung', 'url' => '/vermietung/'],
        ['schluessel' => 'verkauf', 'name' => 'Verkauf', 'url' => '/verkauf/'],
        ['schluessel' => 'gutachten', 'name' => 'Wertgutachten', 'url' => '/wertgutachten/'],
    ],
];
