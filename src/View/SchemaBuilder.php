<?php

declare(strict_types=1);

namespace Hvm\View;

use Hvm\Support\Config;

/**
 * Strukturierte Daten (schema.org) aus den Stammdaten. Nur belegte Angaben:
 * Felder mit null in config/unternehmen.php (z. B. telefon) werden weggelassen.
 */
final class SchemaBuilder
{
    public function __construct(private readonly Config $config)
    {
    }

    public function organizationId(): string
    {
        return $this->baseUrl() . '/#organisation';
    }

    public function websiteId(): string
    {
        return $this->baseUrl() . '/#website';
    }

    private function baseUrl(): string
    {
        return rtrim((string) $this->config->get('app.url', ''), '/');
    }

    /**
     * @param array{slug: string, title: string, description: string, canonical: string, breadcrumbs: list<array{label: string, url: string}>} $page
     * @return list<array<string, mixed>>
     */
    public function forPage(array $page, string $type): array
    {
        $schemas = [];
        if ($page['slug'] === 'start') {
            $schemas[] = $this->organization();
            $schemas[] = $this->website();
        }

        if ($type === 'Service') {
            $schemas[] = self::withoutNulls([
                '@context' => 'https://schema.org',
                '@type' => 'Service',
                'name' => $page['breadcrumbs'] !== [] ? end($page['breadcrumbs'])['label'] : $page['title'],
                'description' => $page['description'],
                'url' => $page['canonical'],
                'provider' => ['@id' => $this->organizationId()],
                // areaServed nur mit bestätigten Regionen (config/standorte.php, status 'eigene_praesenz' oder
                // 'partner', verifiziert true). Aktuell ist nur der Hauptsitz bestätigt, keine Servicegebiete.
                'areaServed' => $this->confirmedAreaServed(),
            ]);
        } elseif ($type !== '' && $type !== 'none') {
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type' => $type,
                'name' => $page['title'],
                'description' => $page['description'],
                'url' => $page['canonical'],
                'inLanguage' => 'de-DE',
                'isPartOf' => ['@type' => 'WebSite', 'url' => $this->baseUrl() . '/'],
            ];
        }

        if (count($page['breadcrumbs']) > 1) {
            $schemas[] = $this->breadcrumbList($page['breadcrumbs']);
        }

        return $schemas;
    }

    /**
     * Organisation und LocalBusiness für den Hauptsitz.
     *
     * @return array<string, mixed>
     */
    public function organization(): array
    {
        $firma = $this->config->array('unternehmen');
        $anschrift = (array) ($firma['anschrift'] ?? []);

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => ['Organization', 'LocalBusiness'],
            '@id' => $this->organizationId(),
            'name' => $firma['name'] ?? '',
            'url' => $this->baseUrl() . '/',
            'email' => $firma['email'] ?? null,
            'foundingDate' => $firma['gruendung'] ?? null,
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $anschrift['strasse'] ?? null,
                'postalCode' => $anschrift['plz'] ?? null,
                'addressLocality' => $anschrift['ort'] ?? null,
                'addressCountry' => $anschrift['land'] ?? 'DE',
            ],
            'telephone' => isset($firma['telefon']) ? preg_replace('/^0/', '+49 ', (string) $firma['telefon']) : null,
            'vatID' => $firma['ust_id'] ?? null,
            'memberOf' => array_map(
                static fn (array $m): array => ['@type' => 'Organization', 'name' => (string) ($m['name'] ?? $m['kurz'] ?? '')],
                array_values((array) ($firma['mitgliedschaften'] ?? []))
            ),
        ];
        if (is_file((string) $this->config->get('app.base_path') . '/public/assets/img/logo/hvm-logo.svg')) {
            $schema['logo'] = $this->baseUrl() . '/assets/img/logo/hvm-logo.svg';
        }

        return self::withoutNulls($schema);
    }

    /**
     * WebSite-Entität, referenziert von den übrigen Seiten über isPartOf.
     * Ohne SearchAction: die Suche ist rein clientseitig (Architektur Abschnitt 1) und liefert keine
     * eigene Ergebnis-URL, die Google als Sitelinks-Suchbox anzeigen könnte.
     *
     * @return array<string, mixed>
     */
    public function website(): array
    {
        return self::withoutNulls([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => $this->websiteId(),
            'name' => (string) $this->config->get('unternehmen.name', ''),
            'url' => $this->baseUrl() . '/',
            'inLanguage' => 'de-DE',
            'publisher' => ['@id' => $this->organizationId()],
        ]);
    }

    /**
     * Bestätigte Servicegebiete aus config/standorte.php (status 'eigene_praesenz' oder 'partner',
     * verifiziert true), ohne den Hauptsitz selbst (der steht bereits in der Anschrift). Leere Liste,
     * solange keine weitere Region bestätigt ist: dann wird areaServed weggelassen, nie geraten.
     *
     * @return list<array<string, mixed>>
     */
    private function confirmedAreaServed(): array
    {
        $orte = [];
        foreach ($this->config->array('standorte') as $standort) {
            $status = $standort['status'] ?? null;
            $verifiziert = $standort['verifiziert'] ?? false;
            $hauptsitz = $standort['hauptsitz'] ?? false;
            if ($hauptsitz || $verifiziert !== true || !in_array($status, ['eigene_praesenz', 'partner'], true)) {
                continue;
            }
            $orte[] = ['@type' => 'City', 'name' => (string) ($standort['name'] ?? '')];
        }

        return $orte;
    }

    /**
     * FAQPage aus einer Liste von Fragen und Antworten. Wiederverwendbar für Wissensartikel und die
     * FAQ-Seite (`/wissen/`); Antworttext als reiner Text ohne HTML.
     *
     * @param list<array{frage: string, antwort: string}> $fragen
     * @return array<string, mixed>
     */
    public function faqPage(array $fragen, string $url): array
    {
        return self::withoutNulls([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'url' => $url,
            'mainEntity' => array_map(
                static fn (array $f): array => [
                    '@type' => 'Question',
                    'name' => $f['frage'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['antwort']],
                ],
                $fragen
            ),
        ]);
    }

    /**
     * Article aus den Frontmatter-Feldern eines Wissensartikels. Nur belegte Angaben: fehlt ein Datum
     * oder ein Autor, bleibt das Feld weg statt geraten zu werden.
     *
     * @param array{titel: string, beschreibung?: string, url: string, stand?: string, autor?: string, bild?: string} $artikel
     * @return array<string, mixed>
     */
    public function article(array $artikel): array
    {
        $bild = $artikel['bild'] ?? null;

        return self::withoutNulls([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $artikel['titel'],
            'description' => $artikel['beschreibung'] ?? null,
            'url' => $artikel['url'],
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $artikel['url']],
            'inLanguage' => 'de-DE',
            'dateModified' => isset($artikel['stand']) ? self::isoDate($artikel['stand']) : null,
            'datePublished' => isset($artikel['stand']) ? self::isoDate($artikel['stand']) : null,
            'author' => isset($artikel['autor']) ? ['@type' => 'Organization', 'name' => $artikel['autor']] : ['@id' => $this->organizationId()],
            'publisher' => ['@id' => $this->organizationId()],
            'image' => $bild !== null ? $this->baseUrl() . $bild : null,
            'isPartOf' => ['@type' => 'WebSite', '@id' => $this->websiteId()],
        ]);
    }

    private static function isoDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value . 'T00:00:00+01:00' : null;
    }

    /**
     * @param list<array{label: string, url: string}> $breadcrumbs
     * @return array<string, mixed>
     */
    public function breadcrumbList(array $breadcrumbs): array
    {
        $items = [];
        foreach (array_values($breadcrumbs) as $i => $crumb) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb['label'],
                'item' => $this->baseUrl() . $crumb['url'],
            ];
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function withoutNulls(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::withoutNulls($value);
            }
            if ($data[$key] === null || $data[$key] === []) {
                unset($data[$key]);
            }
        }

        return $data;
    }
}
