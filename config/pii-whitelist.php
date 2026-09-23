<?php

declare(strict_types=1);

/*
 * Zusätzliche Whitelist für bin/check-pii.php, über die bestätigten Angaben aus
 * config/unternehmen.php (info@muellerhv.de, zentrale Telefonnummer) hinaus.
 * Nur bestätigte, öffentlich vorgesehene Kontaktdaten eintragen, niemals personenbezogene
 * Daten von Mietern oder Eigentümern. Ausnahme: schematische Beispiele aus Hilfetexten der
 * Formulare, jeweils mit Fundstelle kommentiert. Adressen unter example.org, example.com und
 * example.net erkennt bin/check-pii.php selbst als Beispiel (RFC 2606).
 */
return [
    'emails' => [
    ],
    'telefonnummern' => [
        // Beispiel im Fehlertext der Telefonfelder (src/Validation/Validator.php, templates/partials/forms/*)
        '0211 123456',
    ],
];
