<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Content;

use Hvm\Content\WissenRepository;
use Hvm\Content\WissenSitemapProvider;
use Hvm\Support\Config;
use Hvm\Support\Log;
use PHPUnit\Framework\TestCase;

final class WissenSitemapProviderTest extends TestCase
{
    public function testNurVeroeffentlichteArtikelWerdenAufgenommen(): void
    {
        $config = new Config(['app' => ['env' => 'development', 'show_drafts' => false]]);
        $wissen = new WissenRepository($config, new Log(sys_get_temp_dir(), 'hvm-test.log'), __DIR__ . '/../../fixtures/wissen');
        $provider = new WissenSitemapProvider($wissen);

        $urls = iterator_to_array($provider->sitemapUrls());

        self::assertSame([['loc' => '/wissen/beispielartikel/', 'lastmod' => '2026-01-15']], $urls);
    }
}
