<?php

declare(strict_types=1);

/*
 * Zusätzliche Whitelist für bin/check-pii.php, über die bestätigten Angaben aus
 * config/unternehmen.php (info@muellerhv.de, zentrale Telefonnummer) hinaus.
 * Nur bestätigte, öffentlich vorgesehene Kontaktdaten eintragen, niemals personenbezogene
 * Daten von Mietern oder Eigentümern.
 */
return [
    'emails' => [
    ],
    'telefonnummern' => [
    ],
];
