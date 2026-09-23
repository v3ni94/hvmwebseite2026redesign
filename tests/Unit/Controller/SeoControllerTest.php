<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Controller;

use Hvm\Tests\Unit\TestCase;

final class SeoControllerTest extends TestCase
{
    public function testSitemapListsPublicPagesOnly(): void
    {
        $response = $this->get('/sitemap.xml', 'production');
        self::assertSame(200, $response->status());
        self::assertStringStartsWith('application/xml', (string) $response->header('Content-Type'));

        $xml = simplexml_load_string($response->body());
        self::assertNotFalse($xml);
        $locs = [];
        foreach ($xml->url as $url) {
            $locs[] = (string) $url->loc;
        }
        self::assertContains(self::BASE_URL . '/', $locs);
        self::assertContains(self::BASE_URL . '/weg-verwaltung/', $locs);
        self::assertContains(self::BASE_URL . '/impressum/', $locs);
        self::assertNotContains(self::BASE_URL . '/admin/', $locs);
        self::assertNotContains(self::BASE_URL . '/styleguide/', $locs);
        self::assertNotContains(self::BASE_URL . '/angebot/danke/', $locs);
        self::assertContains(self::BASE_URL . '/fakten/', $locs);
        self::assertContains(self::BASE_URL . '/asset-management/', $locs);
        self::assertContains(self::BASE_URL . '/wissen/', $locs);
        self::assertContains(self::BASE_URL . '/kontakt/', $locs);
        self::assertSame(count($locs), count(array_unique($locs)), 'keine doppelten Einträge');
        foreach ($xml->url as $url) {
            if (isset($url->lastmod)) {
                self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $url->lastmod);
            }
        }
    }

    public function testSitemapEnthaeltKeineEntwuerfe(): void
    {
        $response = $this->kernel('staging', ['SHOW_DRAFTS' => 'true'])->handle(\Hvm\Http\Request::create('GET', '/sitemap.xml'));
        self::assertStringNotContainsString('/wissen/hausgeld/', $response->body(), 'Entwürfe nie in der Sitemap');
    }

    public function testRobotsInProduction(): void
    {
        $body = $this->get('/robots.txt', 'production')->body();
        self::assertStringContainsString('Disallow: /admin/', $body);
        self::assertStringContainsString('Sitemap: ' . self::BASE_URL . '/sitemap.xml', $body);
        self::assertStringNotContainsString("Disallow: /\n", $body);
    }

    public function testRobotsInProductionLaesstKiCrawlerZu(): void
    {
        $body = $this->get('/robots.txt', 'production')->body();
        foreach (['GPTBot', 'OAI-SearchBot', 'PerplexityBot', 'ClaudeBot', 'Google-Extended'] as $crawler) {
            self::assertStringContainsString('User-agent: ' . $crawler . "\n", $body);
        }
        self::assertMatchesRegularExpression('/User-agent: Google-Extended\nAllow: \/\nDisallow: \/admin\//', $body);
    }

    public function testRobotsKiCrawlerPerKonfigurationGesperrt(): void
    {
        $response = $this->kernel('production', ['AI_CRAWLERS' => 'false'])->handle(\Hvm\Http\Request::create('GET', '/robots.txt'));
        $body = $response->body();
        self::assertMatchesRegularExpression('/User-agent: Google-Extended\nDisallow: \/\n/', $body);
        self::assertStringNotContainsString('Allow: /', $body);
        // Alle übrigen Crawler bleiben zugelassen
        self::assertStringStartsWith("User-agent: *\nDisallow: /admin/\n", $body);
    }

    public function testLlmsTxtNachKonvention(): void
    {
        $response = $this->get('/llms.txt', 'production');
        self::assertSame(200, $response->status());
        self::assertStringStartsWith('text/markdown', (string) $response->header('Content-Type'));
        $body = $response->body();

        self::assertStringStartsWith("# Hausverwaltung Müller GmbH\n\n> ", $body);
        self::assertStringContainsString('Amtsgericht Düsseldorf, HRB 104762', $body);
        self::assertStringContainsString('869 Verwaltungseinheiten in 67 Objekten (Stand 01.07.2026)', $body);
        self::assertStringContainsString('Gegründet am 04.03.2020', $body);
        self::assertStringContainsString('](' . self::BASE_URL . '/fakten/)', $body);
        self::assertStringContainsString('## Leistungen', $body);
        self::assertStringContainsString('[Asset Management](' . self::BASE_URL . '/asset-management/)', $body);
        self::assertStringContainsString('## Wissen', $body);
        self::assertStringNotContainsString('/styleguide/', $body);
        self::assertStringNotContainsString('/admin/', $body);
    }

    public function testLlmsTxtListetKeineEntwuerfe(): void
    {
        $response = $this->kernel('staging', ['SHOW_DRAFTS' => 'true'])->handle(\Hvm\Http\Request::create('GET', '/llms.txt'));
        self::assertSame(200, $response->status());
        self::assertStringNotContainsString('/wissen/hausgeld/', $response->body(), 'Entwürfe auch außerhalb der Produktion nicht listen');
    }

    public function testRobotsOutsideProductionBlocksAll(): void
    {
        foreach (['development', 'staging'] as $env) {
            $body = $this->get('/robots.txt', $env)->body();
            self::assertSame("User-agent: *\nDisallow: /\n", $body, $env);
        }
    }
}
