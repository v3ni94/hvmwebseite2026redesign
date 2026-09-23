<?php

declare(strict_types=1);

/*
 * Freigabestatus je Seite (Schlüssel wie in config/seiten.php).
 * status: entwurf | freigegeben. datum: JJJJ-MM-TT der Freigabe. durch: freigebende Stelle (z. B. Geschäftsführung).
 * Nicht freigegebene Seiten zeigen außerhalb der Produktion ein Entwurfsband.
 */
$seiten = [
    'start', 'weg-verwaltung', 'mietverwaltung', 'se-verwaltung', 'verwalterwechsel',
    'vermietung', 'verkauf', 'wertgutachten', 'wissen', 'betreuungsgebiete', 'referenzen',
    'ueber-uns', 'karriere', 'karriere-bewerbung', 'service', 'notfall', 'kontakt',
    'angebot', 'angebot-danke', 'impressum', 'datenschutz', 'barrierefreiheit',
];

$freigaben = [];
foreach ($seiten as $seite) {
    $freigaben[$seite] = ['status' => 'entwurf', 'datum' => null, 'durch' => null];
}

return $freigaben;
