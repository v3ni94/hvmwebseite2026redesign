<?php

declare(strict_types=1);

/*
 * Zusätzliche Whitelist für bin/check-pii.php, über die bestätigten Angaben aus
 * config/unternehmen.php (info@muellerhv.de, zentrale Telefonnummer) hinaus.
 * Nur bestätigte, öffentlich vorgesehene Kontaktdaten eintragen, niemals personenbezogene
 * Daten von Mietern oder Eigentümern. Hilfe- und Fehlertexte der Formulare nennen keine
 * konkreten Beispielnummern mehr. Adressen unter example.org, example.com und example.net
 * erkennt bin/check-pii.php selbst als Beispiel (RFC 2606).
 */
return [
    'emails' => [
    ],
    'telefonnummern' => [
    ],
];
