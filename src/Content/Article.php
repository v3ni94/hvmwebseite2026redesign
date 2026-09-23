<?php

declare(strict_types=1);

namespace Hvm\Content;

/**
 * Ein Wissensartikel (content/wissen/{slug}.md), bereits validiert und zu HTML gerendert.
 * Format und Pflichtfelder: docs/wissen-inhaltsplan.md Abschnitt 2.
 */
final class Article
{
    /**
     * @param list<array{frage: string, antwort: string}> $faq Höchstens fünf Einträge (Inhaltsplan)
     * @param list<array{id: string, text: string}>        $ueberschriften H2-Überschriften für das Inhaltsverzeichnis
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $titel,
        public readonly string $zielgruppe,
        public readonly string $beschreibung,
        public readonly string $stand,
        public readonly string $autor,
        public readonly bool $freigegeben,
        public readonly string $leistung,
        public readonly string $cta,
        public readonly array $faq,
        public readonly string $html,
        public readonly array $ueberschriften,
        public readonly bool $istEntwurf,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function zielgruppen(): array
    {
        return ['weg-beirat', 'vermieter-investoren', 'verkauf-bewertung', 'mieter', 'kosten-vertrag'];
    }

    public static function zielgruppeLabel(string $zielgruppe): string
    {
        return match ($zielgruppe) {
            'weg-beirat' => 'WEG und Beirat',
            'vermieter-investoren' => 'Vermieter und Investoren',
            'verkauf-bewertung' => 'Verkauf und Bewertung',
            'mieter' => 'Mieter',
            'kosten-vertrag' => 'Kosten und Vertrag',
            default => $zielgruppe,
        };
    }

    /**
     * @return array<string, string> cta-Schlüssel => Linktext
     */
    public static function ctaLabels(): array
    {
        return [
            'angebot' => 'Angebot anfordern',
            'kontakt' => 'Kontakt aufnehmen',
            'service' => 'Zum Service',
            'notfall' => 'Zur Notfallseite',
        ];
    }

    /**
     * @return array<string, string> cta-Schlüssel => Ziel-URL
     */
    public static function ctaUrls(): array
    {
        return [
            'angebot' => '/angebot/',
            'kontakt' => '/kontakt/',
            'service' => '/service/',
            'notfall' => '/notfall/',
        ];
    }
}
