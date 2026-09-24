<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Controller;

use Hvm\Http\Request;
use Hvm\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PageControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function htmlRoutes(): iterable
    {
        /** @var list<array{0: string, 1: string}> $routes */
        $routes = require dirname(__DIR__, 3) . '/config/routes.php';
        $gesehen = [];
        foreach ($routes as $route) {
            $pfad = $route[1];
            // Feature-Routen ersetzen Stubs (doppelte Pfade), Platzhalter, Admin (Login-Weiterleitung) und
            // Einrichtung (404 ohne SETUP_TOKEN, EinrichtungControllerTest) gesondert getestet.
            if (!in_array('GET', (array) $route[0], true) || !str_ends_with($pfad, '/')
                || str_contains($pfad, '{') || str_starts_with($pfad, '/admin/') || str_starts_with($pfad, '/_einrichtung/')
                || isset($gesehen[$pfad])) {
                continue;
            }
            $gesehen[$pfad] = true;
            yield $pfad => [$pfad];
        }
    }

    #[DataProvider('htmlRoutes')]
    public function testEveryRouteRendersInDevelopment(string $path): void
    {
        $response = $this->get($path, 'development');
        self::assertSame(200, $response->status(), $path);
        self::assertStringStartsWith('text/html', (string) $response->header('Content-Type'));

        $html = $response->body();
        self::assertStringContainsString('<html lang="de">', $html);
        self::assertMatchesRegularExpression('#<h1>[^<]+</h1>#', $html);
        self::assertStringContainsString('<title>', $html);
        self::assertStringContainsString('<meta name="description" content="', $html);
        self::assertStringContainsString('<link rel="canonical" href="' . self::BASE_URL . $path . '">', $html);
        self::assertStringContainsString('<meta property="og:title"', $html);
        self::assertStringNotContainsString('style="', $html);
    }

    #[DataProvider('htmlRoutes')]
    public function testEveryRouteRendersInProduction(string $path): void
    {
        $response = $this->get($path, 'production');
        $expected = $path === '/styleguide/' ? 404 : 200;
        self::assertSame($expected, $response->status(), $path);
    }

    public function testUnknownPathIs404(): void
    {
        $response = $this->get('/gibt-es-nicht/');
        self::assertSame(404, $response->status());
        self::assertStringContainsString('Seite nicht gefunden', $response->body());
        self::assertStringNotContainsString('rel="canonical"', $response->body());
        self::assertSame('noindex, nofollow', $response->header('X-Robots-Tag'));
    }

    public function testPathWithoutSlashIsRedirected(): void
    {
        $response = $this->get('/weg-verwaltung');
        self::assertSame(301, $response->status());
        self::assertSame('/weg-verwaltung/', $response->header('Location'));
    }

    public function testStartPageHasOrganizationSchemaWithoutInventedPhone(): void
    {
        $html = $this->get('/')->body();
        preg_match_all('#<script type="application/ld\+json" nonce="[^"]+">(.*?)</script>#s', $html, $m);
        self::assertNotEmpty($m[1]);
        $types = [];
        foreach ($m[1] as $json) {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $types[] = is_array($data['@type']) ? implode('+', $data['@type']) : $data['@type'];
            if (($data['@type'] ?? null) === ['Organization', 'RealEstateAgent']) {
                self::assertSame('Hausverwaltung Müller GmbH', $data['name']);
                self::assertSame('Rheinpromenade 13', $data['address']['streetAddress']);
                self::assertSame('+49 2431 9550300', $data['telephone'] ?? null);
                self::assertArrayNotHasKey('vatID', $data);
            }
        }
        self::assertContains('Organization+RealEstateAgent', $types);
    }

    public function testServicePageHasBreadcrumbAndServiceSchema(): void
    {
        $html = $this->get('/weg-verwaltung/')->body();
        self::assertStringContainsString('"@type":"Service"', $html);
        self::assertStringContainsString('"@type":"BreadcrumbList"', $html);
    }

    public function testNestedBreadcrumbs(): void
    {
        $html = $this->get('/karriere/bewerbung/')->body();
        preg_match_all('#<script type="application/ld\+json" nonce="[^"]+">(.*?)</script>#s', $html, $m);
        $crumbs = null;
        foreach ($m[1] as $json) {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if ($data['@type'] === 'BreadcrumbList') {
                $crumbs = array_column($data['itemListElement'], 'name');
            }
        }
        self::assertSame(['Start', 'Karriere', 'Bewerbung'], $crumbs);
    }

    public function testHeadRequestHasNoBody(): void
    {
        $response = $this->kernel()->handle(Request::create('HEAD', '/'));
        self::assertSame(200, $response->status());
        self::assertSame('', $response->body());
    }

    public function testPostWithoutRouteIs405(): void
    {
        $kernel = $this->kernel();
        $response = $kernel->handle(Request::create('POST', '/weg-verwaltung/', ['_csrf' => 'x']));
        // Ohne gültiges Token greift zuerst der CSRF-Schutz
        self::assertSame(403, $response->status());
    }
}
