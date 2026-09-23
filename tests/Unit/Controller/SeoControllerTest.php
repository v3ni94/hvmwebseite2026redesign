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
    }

    public function testRobotsInProduction(): void
    {
        $body = $this->get('/robots.txt', 'production')->body();
        self::assertStringContainsString('Disallow: /admin/', $body);
        self::assertStringContainsString('Sitemap: ' . self::BASE_URL . '/sitemap.xml', $body);
        self::assertStringNotContainsString("Disallow: /\n", $body);
    }

    public function testRobotsOutsideProductionBlocksAll(): void
    {
        foreach (['development', 'staging'] as $env) {
            $body = $this->get('/robots.txt', $env)->body();
            self::assertSame("User-agent: *\nDisallow: /\n", $body, $env);
        }
    }
}
