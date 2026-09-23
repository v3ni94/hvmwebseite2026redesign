<?php

declare(strict_types=1);

namespace Hvm\Content;

use Hvm\Support\SitemapProvider;

/**
 * Trägt die veröffentlichten Wissensartikel (/wissen/{slug}/) in die XML-Sitemap ein.
 * Entwürfe (auch wenn sie wegen SHOW_DRAFTS sichtbar sind) werden nie aufgenommen.
 *
 * Registrierung: config/app.php, Schlüssel 'sitemap_providers' (nicht Teil dieser Aufgabe,
 * siehe Bericht/offene Punkte, da config/app.php eine gemeinsame Datei ist).
 */
final class WissenSitemapProvider implements SitemapProvider
{
    public function __construct(private readonly WissenRepository $wissen)
    {
    }

    /**
     * @return iterable<array{loc: string, lastmod?: string|null}>
     */
    public function sitemapUrls(): iterable
    {
        foreach ($this->wissen->veroeffentlichte() as $artikel) {
            if (!$artikel->freigegeben) {
                continue;
            }
            yield ['loc' => '/wissen/' . $artikel->slug . '/', 'lastmod' => $artikel->stand];
        }
    }
}
