<?php

declare(strict_types=1);

namespace Hvm\View;

use Hvm\Support\Config;

/**
 * Strukturierte Daten (schema.org) aus den Stammdaten. Nur belegte Angaben:
 * Felder mit null in config/unternehmen.php (z. B. ust_id) werden weggelassen, nie geraten.
 *
 * Aufbau je indexierbarer Seite (docs/seo-geo.md Abschnitt 3): Organisation und WebSite mit festen @id,
 * dazu die Seitenentität (WebPage, AboutPage, ContactPage, CollectionPage oder Service) und die
 * BreadcrumbList. Die Entitäten verweisen per @id aufeinander, damit Suchmaschinen und
 * Antwortmaschinen sie auf jeder Seite eindeutig einer Organisation zuordnen können.
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
     * @param array{slug: string, title: string, description: string, canonical: string, breadcrumbs: list<array{label: string, url: string}>, heading?: string} $page
     * @return list<array<string, mixed>>
     */
    public function forPage(array $page, string $type): array
    {
        $schemas = [];
        $indexierbar = $type !== '' && $type !== 'none';
        $hatKrumen = count($page['breadcrumbs']) > 1;
        $name = (string) ($page['heading'] ?? ($page['breadcrumbs'] !== [] ? end($page['breadcrumbs'])['label'] : $page['title']));

        if ($indexierbar) {
            $schemas[] = $this->organization();
            $schemas[] = $this->website();
        }

        if ($type === 'Service') {
            $schemas[] = self::withoutNulls([
                '@context' => 'https://schema.org',
                '@type' => 'Service',
                '@id' => $page['canonical'] . '#leistung',
                'name' => $name,
                'serviceType' => $name,
                'description' => $page['description'],
                'url' => $page['canonical'],
                'inLanguage' => 'de-DE',
                'provider' => ['@id' => $this->organizationId()],
                // areaServed nur mit bestätigten Orten (config/standorte.php, verifiziert true): Hauptsitz
                // Monheim am Rhein sowie weitere Regionen erst nach Bestätigung durch die Geschäftsführung.
                'areaServed' => $this->confirmedAreaServed(),
                'mainEntityOfPage' => $page['canonical'],
            ]);
        } elseif ($indexierbar) {
            $ueberOrganisation = in_array($type, ['AboutPage', 'ContactPage'], true);
            $schemas[] = self::withoutNulls([
                '@context' => 'https://schema.org',
                '@type' => $type,
                '@id' => $page['canonical'] . '#webseite',
                'name' => $page['title'],
                'description' => $page['description'],
                'url' => $page['canonical'],
                'inLanguage' => 'de-DE',
                'isPartOf' => ['@id' => $this->websiteId()],
                'about' => $ueberOrganisation || $page['slug'] === 'start' ? ['@id' => $this->organizationId()] : null,
                'publisher' => ['@id' => $this->organizationId()],
                'breadcrumb' => $hatKrumen ? ['@id' => $page['canonical'] . '#brotkrumen'] : null,
            ]);
        }

        if ($hatKrumen) {
            $schemas[] = $this->breadcrumbList($page['breadcrumbs'], $page['canonical']);
        }

        return $schemas;
    }

    /**
     * Organisation für den Hauptsitz. RealEstateAgent ist in schema.org ein LocalBusiness und der
     * nächstliegende Typ für eine Hausverwaltung mit Vermietung und Verkauf.
     *
     * @return array<string, mixed>
     */
    public function organization(): array
    {
        $firma = $this->config->array('unternehmen');
        $anschrift = (array) ($firma['anschrift'] ?? []);
        $telefon = self::internationaleNummer($firma['telefon'] ?? null);
        $logo = null;
        if (is_file((string) $this->config->get('app.base_path') . '/public/assets/img/logo/hvm-logo.svg')) {
            $logo = $this->baseUrl() . '/assets/img/logo/hvm-logo.svg';
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => ['Organization', 'RealEstateAgent'],
            '@id' => $this->organizationId(),
            'name' => $firma['name'] ?? '',
            'legalName' => $firma['name'] ?? null,
            'alternateName' => $firma['kurzname'] ?? null,
            'url' => $this->baseUrl() . '/',
            'logo' => $logo,
            'image' => $logo,
            'email' => $firma['email'] ?? null,
            'telephone' => $telefon,
            'foundingDate' => $firma['gruendung'] ?? null,
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $anschrift['strasse'] ?? null,
                'postalCode' => $anschrift['plz'] ?? null,
                'addressLocality' => $anschrift['ort'] ?? null,
                'addressCountry' => $anschrift['land'] ?? 'DE',
            ],
            'contactPoint' => $telefon !== null || isset($firma['email']) ? [
                '@type' => 'ContactPoint',
                'contactType' => 'customer service',
                'telephone' => $telefon,
                'email' => $firma['email'] ?? null,
                'availableLanguage' => 'de',
            ] : null,
            'vatID' => $firma['ust_id'] ?? null,
            'identifier' => isset($firma['hrb'], $firma['registergericht']) ? [
                '@type' => 'PropertyValue',
                'propertyID' => 'Handelsregister',
                'value' => $firma['hrb'] . ', ' . $firma['registergericht'],
            ] : null,
            'memberOf' => array_map(
                static fn (array $m): array => ['@type' => 'Organization', 'name' => (string) ($m['name'] ?? $m['kurz'] ?? '')]
                    + (isset($m['kurz']) && ($m['kurz'] !== ($m['name'] ?? null)) ? ['alternateName' => (string) $m['kurz']] : []),
                array_values((array) ($firma['mitgliedschaften'] ?? []))
            ),
            'knowsAbout' => array_values(array_map(
                static fn (array $l): string => (string) $l['name'],
                array_filter((array) ($firma['leistungen'] ?? []), static fn ($l): bool => is_array($l) && isset($l['name']))
            )),
            'areaServed' => $this->confirmedAreaServed(),
            // sameAs nur mit bekannten, eigenen Profilen (optional config/unternehmen.php 'same_as'), sonst weggelassen
            'sameAs' => array_values(array_filter((array) ($firma['same_as'] ?? []), 'is_string')),
        ];

        return self::withoutNulls($schema);
    }

    /**
     * WebSite-Entität mit SearchAction auf die serverseitige Suche der Wissensseite (/wissen/?q=).
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
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $this->baseUrl() . '/wissen/?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ]);
    }

    /**
     * Bestätigte Orte aus config/standorte.php: Hauptsitz sowie Regionen mit status 'eigene_praesenz'
     * oder 'partner' und verifiziert true. Nicht bestätigte Regionen werden nie aufgenommen.
     *
     * @return list<array<string, mixed>>
     */
    private function confirmedAreaServed(): array
    {
        $orte = [];
        foreach ($this->config->array('standorte') as $standort) {
            $status = $standort['status'] ?? null;
            $verifiziert = $standort['verifiziert'] ?? false;
            if ($verifiziert !== true || !in_array($status, ['eigene_praesenz', 'partner'], true)) {
                continue;
            }
            $orte[] = ['@type' => 'City', 'name' => (string) ($standort['name'] ?? '')];
        }

        return $orte;
    }

    /**
     * FAQPage aus einer Liste von Fragen und Antworten. Nur freigegebene Fragen übergeben
     * (Aufrufer filtert). Antworttext als reiner Text ohne HTML.
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
            'inLanguage' => 'de-DE',
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
     * Article aus den Frontmatter-Feldern eines Wissensartikels. Nur belegte Angaben: fehlt ein Datum,
     * bleibt das Feld weg. Autor ist die Organisation, sofern das Frontmatter den Firmennamen nennt.
     *
     * @param array{titel: string, beschreibung?: string, url: string, stand?: string, autor?: string, bild?: string} $artikel
     * @return array<string, mixed>
     */
    public function article(array $artikel): array
    {
        $bild = $artikel['bild'] ?? null;
        $stand = isset($artikel['stand']) ? self::isoDate($artikel['stand']) : null;
        $firma = (string) $this->config->get('unternehmen.name', '');
        $autor = $artikel['autor'] ?? null;
        if ($autor === null || $autor === $firma) {
            $autorSchema = ['@type' => 'Organization', '@id' => $this->organizationId(), 'name' => $firma, 'url' => $this->baseUrl() . '/'];
        } else {
            $autorSchema = ['@type' => 'Organization', 'name' => $autor];
        }

        return self::withoutNulls([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            '@id' => $artikel['url'] . '#artikel',
            'headline' => $artikel['titel'],
            'description' => $artikel['beschreibung'] ?? null,
            'url' => $artikel['url'],
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $artikel['url']],
            'inLanguage' => 'de-DE',
            'dateModified' => $stand,
            'datePublished' => $stand,
            'author' => $autorSchema,
            'publisher' => ['@id' => $this->organizationId()],
            'image' => $bild !== null ? (str_starts_with($bild, 'http') ? $bild : $this->baseUrl() . $bild) : null,
            'isPartOf' => ['@id' => $this->websiteId()],
        ]);
    }

    private static function isoDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    /** Nationale Rufnummer (0...) in internationale Schreibweise (+49 ...) umwandeln. */
    private static function internationaleNummer(mixed $nummer): ?string
    {
        if (!is_string($nummer) || trim($nummer) === '') {
            return null;
        }

        return (string) preg_replace('/^0/', '+49 ', trim($nummer));
    }

    /**
     * @param list<array{label: string, url: string}> $breadcrumbs
     * @return array<string, mixed>
     */
    public function breadcrumbList(array $breadcrumbs, ?string $canonical = null): array
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

        return self::withoutNulls([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            '@id' => $canonical !== null ? $canonical . '#brotkrumen' : null,
            'itemListElement' => $items,
        ]);
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
