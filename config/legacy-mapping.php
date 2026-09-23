<?php

declare(strict_types=1);

/*
 * Zuordnung der Alttabelle "Properties" (docs/bestandsaufnahme-muellerhv-de.md Abschnitt 4) auf leads.
 * Verwendet von bin/import-legacy-leads.php (Hvm\Service\LegacyImport).
 *
 * Die Werte der Altdatenbank sind nicht bekannt. Einträge erst ergänzen, wenn der Export vorliegt
 * [Export alte Lead-Tabelle bereitstellen]. Nichts raten: Zeilen ohne Zuordnung der Verwaltungsform
 * werden nicht importiert und im Importprotokoll mit legacy_id und Grund aufgeführt.
 */
return [
    // managementform_id => management_form ('weg', 'miet' oder 'se'). [Mapping aus Altdatenbank ergänzen]
    'managementform' => [
        // Beispielschema, nicht aktiv: 1 => 'weg',
    ],

    // Preisstufen der Altdatenbank => price_tiers.id. [Mapping aus Altdatenbank ergänzen]
    // Ohne Eintrag wird price_tiers.legacy_id gesucht (passender Einheitentyp), sonst bleibt das Feld leer.
    'preisstufen' => [
        'private' => [],
        'commercial' => [],
        'parking' => [],
    ],

    // Contact Gender => contact_salutation ('frau', 'herr', 'keine'). Vergleich ohne Groß- und Kleinschreibung.
    // Textwerte sind vorbelegt, Zahlencodes der Altdatenbank fehlen. [Mapping aus Altdatenbank ergänzen]
    'anrede' => [
        'frau' => 'frau',
        'herr' => 'herr',
        'female' => 'frau',
        'male' => 'herr',
    ],

    // Status importierter Leads. [Status für Altbestand festlegen]
    'status' => 'neu',

    // Quelle, wenn das Feld Source leer ist. Füllt die Kanalzuordnung nicht rückwirkend, macht den Altbestand im Filter erkennbar.
    'quelle_ohne_angabe' => 'altbestand',

    // Zeitzone der Zeitstempel (Created At) in der Altdatenbank. [Zeitzone der Altdatenbank prüfen]
    'zeitzone' => 'Europe/Berlin',
];
