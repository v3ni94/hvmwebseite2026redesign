<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Http;

use Hvm\Controller\PageController;
use Hvm\Http\RouteMatch;
use Hvm\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(string $env = 'development'): Router
    {
        return Router::fromArray([
            ['GET', '/', [PageController::class, 'show'], ['page' => 'start']],
            ['GET', '/weg-verwaltung/', [PageController::class, 'show'], ['page' => 'weg-verwaltung']],
            ['POST', '/angebot/', [PageController::class, 'show'], ['page' => 'angebot']],
            ['GET', '/wissen/{slug}/', [PageController::class, 'show'], ['page' => 'wissen-artikel']],
            ['GET', '/styleguide/', [PageController::class, 'show'], ['page' => 'styleguide'], ['nicht_in' => ['production']]],
        ], $env);
    }

    public function testStaticRouteMatches(): void
    {
        $match = $this->router()->match('GET', '/weg-verwaltung/');
        self::assertTrue($match->isFound());
        self::assertSame([PageController::class, 'show'], $match->handler);
        self::assertSame(['page' => 'weg-verwaltung'], $match->params);
    }

    public function testHeadIsTreatedAsGet(): void
    {
        self::assertTrue($this->router()->match('HEAD', '/')->isFound());
    }

    public function testPlaceholderRouteExtractsSlug(): void
    {
        $match = $this->router()->match('GET', '/wissen/verwalterwechsel-ablauf/');
        self::assertTrue($match->isFound());
        self::assertSame('verwalterwechsel-ablauf', $match->params['slug']);
        self::assertSame('wissen-artikel', $match->params['page']);
    }

    public function testPlaceholderRejectsInvalidCharacters(): void
    {
        self::assertSame(RouteMatch::NOT_FOUND, $this->router()->match('GET', '/wissen/Gross_Schreibung/')->status);
        self::assertSame(RouteMatch::NOT_FOUND, $this->router()->match('GET', '/wissen/a/b/')->status);
        self::assertSame(RouteMatch::NOT_FOUND, $this->router()->match('GET', '/wissen/../')->status);
    }

    public function testUnknownPathIsNotFound(): void
    {
        self::assertSame(RouteMatch::NOT_FOUND, $this->router()->match('GET', '/gibt-es-nicht/')->status);
    }

    public function testWrongMethodIsMethodNotAllowed(): void
    {
        $match = $this->router()->match('POST', '/weg-verwaltung/');
        self::assertSame(RouteMatch::METHOD_NOT_ALLOWED, $match->status);
        self::assertContains('GET', $match->allowedMethods);
        self::assertContains('HEAD', $match->allowedMethods);

        self::assertSame(RouteMatch::METHOD_NOT_ALLOWED, $this->router()->match('GET', '/angebot/')->status);
    }

    public function testRouteExcludedInProduction(): void
    {
        self::assertTrue($this->router('development')->match('GET', '/styleguide/')->isFound());
        self::assertSame(RouteMatch::NOT_FOUND, $this->router('production')->match('GET', '/styleguide/')->status);
    }

    public function testStaticGetRoutesListsOnlyStaticGetRoutes(): void
    {
        $paths = array_column($this->router()->staticGetRoutes(), 'path');
        self::assertSame(['/', '/weg-verwaltung/', '/styleguide/'], $paths);
    }
}
