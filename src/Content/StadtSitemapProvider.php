<?php

declare(strict_types=1);

namespace Hvm\Content;

use Hvm\Support\SitemapProvider;

/**
 * Trägt die freigegebenen Stadtseiten (/hausverwaltung-{slug}/) in die XML-Sitemap ein.
 * Entwürfe werden nie aufgenommen, auch wenn sie wegen SHOW_DRAFTS sichtbar sind (wie Wissensartikel).
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
        foreach ($this->staedte->freigegebeneSeiten() as $seite) {
            yield ['loc' => $seite->stadt->pfad(), 'lastmod' => $seite->stand];
        }
    }
}
