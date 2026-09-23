<?php

declare(strict_types=1);

/*
 * Navigation. Einträge: label, url, optional kinder (Untermenü) und beschreibung (Kurztext im Untermenü).
 * url null bedeutet: reiner Menüknopf ohne eigene Seite.
 */
return [
    'haupt' => [
        [
            'label' => 'Leistungen',
            'url' => null,
            'kinder' => [
                ['label' => 'WEG-Verwaltung', 'url' => '/weg-verwaltung/', 'beschreibung' => 'für Wohnungseigentümergemeinschaften'],
                ['label' => 'Mietverwaltung', 'url' => '/mietverwaltung/', 'beschreibung' => 'für Vermieter und Investoren'],
                ['label' => 'SE-Verwaltung', 'url' => '/se-verwaltung/', 'beschreibung' => 'für vermietete Eigentumswohnungen'],
                ['label' => 'Asset Management', 'url' => '/asset-management/', 'beschreibung' => 'Strategie für Wohnimmobilien'],
                ['label' => 'Verwalterwechsel', 'url' => '/verwalterwechsel/', 'beschreibung' => 'Ablauf und Zeitplan'],
                ['label' => 'Vermietung', 'url' => '/vermietung/', 'beschreibung' => 'Vermietung im Auftrag der Eigentümer'],
                ['label' => 'Verkauf', 'url' => '/verkauf/', 'beschreibung' => 'Verkauf von Immobilien'],
                ['label' => 'Wertgutachten', 'url' => '/wertgutachten/', 'beschreibung' => 'Anlässe und Ablauf'],
            ],
        ],
        ['label' => 'Wissen', 'url' => '/wissen/'],
        ['label' => 'Betreuungsgebiete', 'url' => '/betreuungsgebiete/'],
        ['label' => 'Über uns', 'url' => '/ueber-uns/'],
        ['label' => 'Service für Mieter und Eigentümer', 'kurzlabel' => 'Service', 'url' => '/service/'],
        ['label' => 'Kontakt', 'url' => '/kontakt/'],
    ],

    'cta' => ['label' => 'Angebot anfordern', 'url' => '/angebot/'],
    'cta_sekundaer' => ['label' => 'Verwalter wechseln', 'url' => '/verwalterwechsel/'],

    'footer' => [
        [
            'titel' => 'Leistungen',
            'links' => [
                ['label' => 'WEG-Verwaltung', 'url' => '/weg-verwaltung/'],
                ['label' => 'Mietverwaltung', 'url' => '/mietverwaltung/'],
                ['label' => 'SE-Verwaltung', 'url' => '/se-verwaltung/'],
                ['label' => 'Asset Management', 'url' => '/asset-management/'],
                ['label' => 'Verwalterwechsel', 'url' => '/verwalterwechsel/'],
                ['label' => 'Vermietung', 'url' => '/vermietung/'],
                ['label' => 'Verkauf', 'url' => '/verkauf/'],
                ['label' => 'Wertgutachten', 'url' => '/wertgutachten/'],
            ],
        ],
        [
            'titel' => 'Unternehmen',
            'links' => [
                ['label' => 'Über uns', 'url' => '/ueber-uns/'],
                ['label' => 'Zahlen und Fakten', 'url' => '/fakten/'],
                ['label' => 'Betreuungsgebiete', 'url' => '/betreuungsgebiete/'],
                ['label' => 'Referenzen', 'url' => '/referenzen/'],
                ['label' => 'Karriere', 'url' => '/karriere/'],
                ['label' => 'Wissen und FAQ', 'url' => '/wissen/'],
            ],
        ],
        [
            'titel' => 'Service',
            'links' => [
                ['label' => 'Service für Mieter und Eigentümer', 'url' => '/service/'],
                ['label' => 'Notfall', 'url' => '/notfall/'],
                ['label' => 'Kontakt', 'url' => '/kontakt/'],
                ['label' => 'Angebot anfordern', 'url' => '/angebot/'],
            ],
        ],
        [
            'titel' => 'Rechtliches',
            'links' => [
                ['label' => 'Impressum', 'url' => '/impressum/'],
                ['label' => 'Datenschutz', 'url' => '/datenschutz/'],
                ['label' => 'Barrierefreiheit', 'url' => '/barrierefreiheit/'],
            ],
        ],
    ],

    // Mobile Bottom-Bar (MP 3.5). typ telefon: Nummer aus config/unternehmen.php, bei null Platzhalter.
    'mobil' => [
        ['label' => 'Angebot', 'url' => '/angebot/', 'typ' => 'link'],
        ['label' => 'Anrufen', 'url' => null, 'typ' => 'telefon', 'quelle' => 'telefon'],
        ['label' => 'Notfall', 'url' => '/notfall/', 'typ' => 'link'],
    ],
];
