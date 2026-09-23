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
        }

        if ($type === 'Service') {
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type' => 'Service',
                'name' => $page['breadcrumbs'] !== [] ? end($page['breadcrumbs'])['label'] : $page['title'],
                'description' => $page['description'],
                'url' => $page['canonical'],
                'provider' => ['@id' => $this->organizationId()],
                'areaServed' => ['@type' => 'Country', 'name' => 'Deutschland'],
            ];
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
        if (is_file((string) $this->config->get('app.base_path') . '/public/assets/img/logo.svg')) {
            $schema['logo'] = $this->baseUrl() . '/assets/img/logo.svg';
        }

        return self::withoutNulls($schema);
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
