<?php

declare(strict_types=1);

namespace Hvm\Content;

/**
 * Ein Betreuungsgebiet aus config/staedte.php (Stadt mit PLZ-Bereich). Keine Anschrift, kein Büro.
 */
final class Stadt
{
    /**
     * @param array{0: float, 1: float} $geo          [lon, lat] des Stadtzentrums (ungefähr, öffentlich bekannt)
     * @param list<string>              $bestandOrte  Orte mit bereits verwalteten Objekten (nur Ortsnamen)
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $bundesland,
        public readonly string $plzVon,
        public readonly string $plzBis,
        public readonly array $geo,
        public readonly array $bestandOrte,
        public readonly bool $hauptsitz,
    ) {
    }

    /**
     * @param array<string, mixed> $eintrag
     */
    public static function ausKonfiguration(array $eintrag): self
    {
        $geo = array_values((array) ($eintrag['geo'] ?? [0.0, 0.0]));

        return new self(
            slug: (string) $eintrag['slug'],
            name: (string) $eintrag['name'],
            bundesland: (string) ($eintrag['bundesland'] ?? ''),
            plzVon: (string) ($eintrag['plz_von'] ?? ''),
            plzBis: (string) ($eintrag['plz_bis'] ?? ''),
            geo: [(float) ($geo[0] ?? 0.0), (float) ($geo[1] ?? 0.0)],
            bestandOrte: array_values(array_map('strval', (array) ($eintrag['bestand_orte'] ?? []))),
            hauptsitz: ($eintrag['hauptsitz'] ?? false) === true,
        );
    }

    public function pfad(): string
    {
        return '/hausverwaltung-' . $this->slug . '/';
    }

    /**
     * Position auf der Deutschlandkarte (templates/partials/karte-deutschland.html.twig, viewBox 0 0 400 520).
     * Gleiche Mercator-Projektion wie der Kartenumriss: Maßstab 42,898 Einheiten je Längengrad,
     * Bezugspunkt Monheim am Rhein (6,8895 / 51,0967) bei x 50,3 und y 271,5.
     *
     * @return array{0: float, 1: float}
     */
    public function karte(): array
    {
        $massstab = 42.898;
        $mercator = static fn (float $lat): float => log(tan(M_PI / 4 + deg2rad($lat) / 2));
        $x = 50.3 + $massstab * ($this->geo[0] - 6.8895);
        $y = 271.5 - $massstab * 180 / M_PI * ($mercator($this->geo[1]) - $mercator(51.0967));

        return [round($x, 1), round($y, 1)];
    }

    /** Luftlinie in Kilometern (Haversine), nur zur Auswahl benachbarter Stadtseiten. */
    public function entfernungKm(self $andere): float
    {
        [$lon1, $lat1] = $this->geo;
        [$lon2, $lat2] = $andere->geo;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
