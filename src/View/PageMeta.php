<?php

declare(strict_types=1);

namespace Hvm\View;

use Hvm\Support\Config;

/**
 * Baut die Twig-Variable "page" aus config/seiten.php und config/freigaben.php:
 * slug, title, description, canonical, breadcrumbs, freigabe, og_image, schema.
 */
final class PageMeta
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param array<string, mixed> $overrides z. B. title oder description für dynamische Seiten
     * @return array{slug: string, title: string, description: string, canonical: string, breadcrumbs: list<array{label: string, url: string}>, freigabe: array<string, mixed>, og_image: ?string, schema: list<array<string, mixed>>, heading: string}
     */
    public function build(string $slug, ?string $path = null, array $overrides = []): array
    {
        $meta = $this->config->array('seiten.' . $slug);
        $path ??= (string) ($meta['pfad'] ?? '/');
        $baseUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        $firma = (string) $this->config->get('unternehmen.name', '');

        $label = (string) ($overrides['heading'] ?? $meta['titel'] ?? $slug);
        $title = (string) ($overrides['title'] ?? $meta['seitentitel'] ?? ($label . ' | ' . $firma));
        $description = (string) ($overrides['description'] ?? $meta['beschreibung'] ?? '');
        $canonical = $baseUrl . $path;
        $breadcrumbs = $this->breadcrumbs($slug, $label, $path);

        $page = [
            'slug' => $slug,
            'heading' => $label,
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'breadcrumbs' => $breadcrumbs,
            'freigabe' => $this->freigabe($slug),
            'og_image' => ($meta['og'] ?? true) === false ? null : $baseUrl . '/og/' . $slug . '.png',
            'schema' => [],
        ];
        $page['schema'] = (new SchemaBuilder($this->config))->forPage($page, (string) ($meta['schema'] ?? 'WebPage'));

        return array_merge($page, array_intersect_key($overrides, ['schema' => true, 'og_image' => true, 'canonical' => true]));
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function breadcrumbs(string $slug, string $label, string $path): array
    {
        if ($slug === 'start') {
            return [];
        }
        $trail = [['label' => $label, 'url' => $path]];
        $parent = $this->config->get('seiten.' . $slug . '.eltern');
        $guard = 0;
        while (is_string($parent) && $parent !== '' && $guard++ < 10) {
            $meta = $this->config->array('seiten.' . $parent);
            $trail[] = ['label' => (string) ($meta['titel'] ?? $parent), 'url' => (string) ($meta['pfad'] ?? '/')];
            $parent = $meta['eltern'] ?? null;
        }
        $trail[] = ['label' => 'Start', 'url' => '/'];

        return array_reverse($trail);
    }

    /**
     * @return array{status: string, datum: ?string, durch: ?string}
     */
    private function freigabe(string $slug): array
    {
        $entry = $this->config->array('freigaben.' . $slug);

        return [
            'status' => (string) ($entry['status'] ?? 'entwurf'),
            'datum' => isset($entry['datum']) ? (string) $entry['datum'] : null,
            'durch' => isset($entry['durch']) ? (string) $entry['durch'] : null,
        ];
    }
}
