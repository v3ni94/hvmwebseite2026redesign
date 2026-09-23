<?php

declare(strict_types=1);

namespace Hvm\Support;

/**
 * Erweiterungspunkt für die XML-Sitemap (z. B. Wissensartikel).
 * Implementierungen werden in config/app.php unter 'sitemap_providers' eingetragen.
 */
interface SitemapProvider
{
    /**
     * @return iterable<array{loc: string, lastmod?: string|null}> loc als Pfad mit führendem und abschließendem Schrägstrich
     */
    public function sitemapUrls(): iterable;
}
