<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Http;

use Hvm\Http\Middleware\LegacyRedirects;
use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Tests\Unit\TestCase;

final class LegacyRedirectsTest extends TestCase
{
    private function middleware(): LegacyRedirects
    {
        /** @var list<array{von: string, nach?: ?string, status?: int, typ?: string}> $rules */
        $rules = require self::basePath() . '/config/redirects.php';

        return new LegacyRedirects($rules);
    }

    private function call(string $uri): Response
    {
        return $this->middleware()->process(Request::create('GET', $uri), static fn (): Response => Response::text('weiter'));
    }

    public function testExactRedirect(): void
    {
        $response = $this->call('/hausverwaltung/');
        self::assertSame(301, $response->status());
        self::assertSame('/weg-verwaltung/', $response->header('Location'));
    }

    public function testExactRedirectWithoutTrailingSlashAndUppercase(): void
    {
        $response = $this->call('/Gutachten');
        self::assertSame(301, $response->status());
        self::assertSame('/wertgutachten/', $response->header('Location'));
    }

    public function testQueryStringIsKept(): void
    {
        self::assertSame('/hausverwaltung-monheim-am-rhein/?utm_source=test', $this->call('/monheim/?utm_source=test')->header('Location'));
    }

    public function testAlteStandortseitenFuehrenZurPassendenStadtseite(): void
    {
        $erwartet = [
            '/muenchen/' => '/hausverwaltung-muenchen/',
            '/konstanz/' => '/hausverwaltung-konstanz/',
            '/monheim/' => '/hausverwaltung-monheim-am-rhein/',
            '/bernau-bei-berlin/' => '/hausverwaltung-berlin/',
            '/berlin/' => '/hausverwaltung-berlin/',
            '/hameln/' => '/hausverwaltung-hannover/',
            '/koeln/' => '/hausverwaltung-koeln/',
            '/koeln-bonn/' => '/hausverwaltung-koeln/',
            '/duesseldorf/' => '/hausverwaltung-duesseldorf/',
            '/hamburg/' => '/hausverwaltung-hamburg/',
            '/hannover/' => '/hausverwaltung-hannover/',
            '/frankfurt/' => '/hausverwaltung-frankfurt-am-main/',
            '/essen/' => '/hausverwaltung-essen/',
            '/kassel/' => '/hausverwaltung-kassel/',
            '/erkelenz/' => '/hausverwaltung-erkelenz/',
        ];
        foreach ($erwartet as $alt => $neu) {
            $response = $this->call($alt);
            self::assertSame(301, $response->status(), $alt);
            self::assertSame($neu, $response->header('Location'), $alt);
        }
    }

    public function testZieleDerStadtweiterleitungenSindStaedteAusDerKonfiguration(): void
    {
        $slugs = array_column(require self::basePath() . '/config/staedte.php', 'slug');
        foreach (require self::basePath() . '/config/redirects.php' as $regel) {
            if (is_string($regel['nach'] ?? null) && str_starts_with($regel['nach'], '/hausverwaltung-')) {
                $slug = substr($regel['nach'], strlen('/hausverwaltung-'), -1);
                self::assertContains($slug, $slugs, $regel['von']);
            }
        }
    }

    public function testStadtseitenSelbstWerdenNichtUmgeleitet(): void
    {
        self::assertSame('weiter', $this->call('/hausverwaltung-koeln/')->body());
    }

    public function testPrefixRedirect(): void
    {
        $response = $this->call('/portfolio/mfh-moenchengladbach/');
        self::assertSame(301, $response->status());
        self::assertSame('/referenzen/', $response->header('Location'));

        self::assertSame('/referenzen/', $this->call('/portfolio')->header('Location'));
    }

    public function testPrefixDoesNotMatchSimilarPath(): void
    {
        self::assertSame('weiter', $this->call('/portfolio-alt/')->body());
    }

    public function testTemporaryRedirectForEstates(): void
    {
        $response = $this->call('/ff/immobilien/estates/00000000-0000-4000-8000-000000000000');
        self::assertSame(302, $response->status());
        self::assertSame('/verkauf/', $response->header('Location'));
    }

    public function testGone(): void
    {
        foreach (['/wp-login.php', '/wp-admin/', '/wp-admin/admin-ajax.php', '/xmlrpc.php', '/feed/'] as $path) {
            $response = $this->call($path);
            self::assertSame(410, $response->status(), $path);
            self::assertNull($response->header('Location'), $path);
        }
    }

    public function testKeptUrlsAreNotRedirected(): void
    {
        foreach (['/vermietung/', '/verkauf/', '/betreuungsgebiete/', '/impressum/'] as $path) {
            self::assertSame('weiter', $this->call($path)->body(), $path);
        }
    }

    public function testRedirectTargetsAreRoutedPages(): void
    {
        foreach ((array) require self::basePath() . '/config/redirects.php' as $rule) {
            if ($rule['status'] === 410) {
                continue;
            }
            self::assertSame(200, $this->get((string) $rule['nach'])->status(), (string) $rule['nach']);
        }
    }

    public function testInvalidStatusIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LegacyRedirects([['von' => '/a/', 'nach' => '/b/', 'status' => 200]]);
    }
}
