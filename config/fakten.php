<?php

declare(strict_types=1);

/*
 * Ergänzende Angaben für die Faktenseite /fakten/ und /llms.txt (docs/seo-geo.md).
 * Stammdaten und Kennzahlen kommen aus config/unternehmen.php und config/kennzahlen.php.
 * Hier stehen nur Angaben, die die Geschäftsführung bestätigt hat (docs/auftraggeber-angaben.md),
 * jeweils mit Stand-Datum. Keine neuen Fakten ergänzen, ohne sie dort zu dokumentieren.
 */
return [
    // Stand der Stammdaten (Firma, Sitz, Register, Geschäftsführung, Kontakt): Bestätigung vom 23.09.2026
    'stand_stammdaten' => '2026-09-23',

    // Definitionen der Leistungsbegriffe in einem Satz, abgeleitet aus den Leistungsseiten
    'definitionen' => [
        [
            'begriff' => 'WEG-Verwaltung',
            'url' => '/weg-verwaltung/',
            'definition' => 'Die WEG-Verwaltung ist die Verwaltung des gemeinschaftlichen Eigentums einer Gemeinschaft der Wohnungseigentümer durch den Verwalter als deren Organ, der Beschlüsse umsetzt, Wirtschaftsplan und Jahresabrechnung erstellt, Eigentümerversammlungen durchführt und Erhaltungsmaßnahmen steuert.',
            'stand' => '2026-09-23',
        ],
        [
            'begriff' => 'Mietverwaltung',
            'url' => '/mietverwaltung/',
            'definition' => 'Die Mietverwaltung ist die laufende Verwaltung vermieteter Wohn- und Gewerbeeinheiten im Auftrag von Vermietern und Investoren mit Mietvertragsmanagement, Mietinkasso, Betriebskostenabrechnung und Instandhaltung.',
            'stand' => '2026-09-23',
        ],
        [
            'begriff' => 'SE-Verwaltung',
            'url' => '/se-verwaltung/',
            'definition' => 'Die Sondereigentumsverwaltung ist die Betreuung einer einzelnen vermieteten Eigentumswohnung für Kapitalanleger, also des Mietverhältnisses, der Betriebskostenabrechnung und der Schnittstelle zur WEG-Verwaltung des Objekts.',
            'stand' => '2026-09-23',
        ],
        [
            'begriff' => 'Asset Management',
            'url' => '/asset-management/',
            'definition' => 'Asset Management ist die strategische Steuerung von Wohnimmobilien für Kapitalanleger, Family Offices und Eigentümer mehrerer Objekte mit Bestandsanalyse, Objektstrategie, Investitionsplanung und Berichtswesen.',
            'stand' => '2026-09-23',
        ],
    ],

    // Bestätigte Merkmale (docs/auftraggeber-angaben.md, jeweils Angabe der Geschäftsführung vom 23.09.2026)
    'merkmale' => [
        ['merkmal' => '24/7-Notdienst', 'angabe' => 'Notdienst rund um die Uhr für Notfälle im Objekt', 'url' => '/notfall/', 'stand' => '2026-09-23'],
        ['merkmal' => 'Eigentümerportal', 'angabe' => 'Portal für Eigentümer und Mieter mit Ticketsystem', 'url' => '/service/', 'stand' => '2026-09-23'],
        ['merkmal' => 'Rahmenverträge', 'angabe' => 'Rahmenverträge für Versicherung, Gas, Strom, Hausmeisterdienste und Messdienst', 'url' => null, 'stand' => '2026-09-23'],
        ['merkmal' => 'Aufnahmegebühr', 'angabe' => 'keine Aufnahmegebühr', 'url' => '/angebot/', 'stand' => '2026-09-23'],
        ['merkmal' => 'Versammlungen', 'angabe' => 'Online-Teilnahme an Eigentümerversammlungen und virtuelle Versammlungen werden angeboten', 'url' => null, 'stand' => '2026-09-23'],
        ['merkmal' => 'Verwaltervertrag', 'angabe' => 'Entwurf des Verwaltervertrags wird mit dem Angebot übersandt', 'url' => '/angebot/', 'stand' => '2026-09-23'],
    ],
];
