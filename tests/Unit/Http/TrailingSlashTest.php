<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Http;

use Hvm\Http\Middleware\TrailingSlash;
use Hvm\Http\Request;
use Hvm\Http\Response;
use PHPUnit\Framework\TestCase;

final class TrailingSlashTest extends TestCase
{
    private function call(Request $request): Response
    {
        return (new TrailingSlash())->process($request, static fn (): Response => Response::text('weiter'));
    }

    public function testRedirectsPathWithoutSlash(): void
    {
        $response = $this->call(Request::create('GET', '/weg-verwaltung'));
        self::assertSame(301, $response->status());
        self::assertSame('/weg-verwaltung/', $response->header('Location'));
    }

    public function testKeepsQueryString(): void
    {
        $response = $this->call(Request::create('GET', '/angebot?art=weg&utm_source=test'));
        self::assertSame('/angebot/?art=weg&utm_source=test', $response->header('Location'));
    }

    public function testPassesPathWithSlash(): void
    {
        self::assertSame('weiter', $this->call(Request::create('GET', '/weg-verwaltung/'))->body());
        self::assertSame('weiter', $this->call(Request::create('GET', '/'))->body());
    }

    public function testPassesFilesWithExtension(): void
    {
        self::assertSame('weiter', $this->call(Request::create('GET', '/sitemap.xml'))->body());
        self::assertSame('weiter', $this->call(Request::create('GET', '/robots.txt'))->body());
    }

    public function testDoesNotRedirectPost(): void
    {
        self::assertSame('weiter', $this->call(Request::create('POST', '/angebot'))->body());
    }

    public function testNoOpenRedirectWithDoubleSlash(): void
    {
        $response = $this->call(Request::create('GET', '//example'));
        self::assertSame(301, $response->status());
        self::assertSame('/example/', $response->header('Location'));
    }
}
