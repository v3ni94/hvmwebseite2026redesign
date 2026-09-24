<?php

declare(strict_types=1);

namespace Hvm\Content;

use Hvm\Support\SitemapProvider;

/**
 * Trägt die indexierbaren Stadtseiten (/hausverwaltung-{slug}/) in die XML-Sitemap ein: freigegeben und mit
 * lokalem Bezug (config/staedte.php indexierbar). Entwürfe werden nie aufgenommen, auch wenn sie wegen
 * SHOW_DRAFTS sichtbar sind (wie Wissensartikel), Städte ohne lokalen Bezug ebenfalls nicht (noindex).
 */
final class StadtSitemapProvider implements SitemapProvider
{
    public function __construct(private readonly StadtRepository $staedte)
    {
    }

    /**
     * @return iterable<array{loc: string, lastmod?: string|null}>
     */
    public function sitemapUrls(): iterable
    {
        foreach ($this->staedte->indexierbareSeiten() as $seite) {
            yield ['loc' => $seite->stadt->pfad(), 'lastmod' => $seite->stand];
        }
    }
}
