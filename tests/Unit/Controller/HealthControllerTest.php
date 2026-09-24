<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Controller;

use Hvm\Http\Request;
use Hvm\Tests\Unit\TestCase;

/**
 * /health ohne Datenbank: Antwort 200 (Container bleibt im Routing), Datenbank "nein", keine Interna.
 * Der Fall mit erreichbarer Datenbank steht in tests/Integration/HealthTest.
 */
final class HealthControllerTest extends TestCase
{
    public function testReportsMissingDatabaseWithoutDetails(): void
    {
        $response = $this->kernel('production', ['DB_NAME' => null])->handle(Request::create('GET', '/health'));
        self::assertSame(200, $response->status());
        self::assertStringStartsWith('application/json', (string) $response->header('Content-Type'));
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->header('X-Robots-Tag'));
        self::assertSame(['status' => 'eingeschraenkt', 'datenbank' => 'nein'], json_decode($response->body(), true));
        self::assertDoesNotMatchRegularExpression('#/home/|/var/www|PHP|\d+\.\d+\.\d+|version#i', $response->body());
    }

    public function testNoTrailingSlashRedirectAndHeadWorks(): void
    {
        $kernel = $this->kernel('development', ['DB_NAME' => null]);
        self::assertSame(200, $kernel->handle(Request::create('GET', '/health'))->status());
        $head = $kernel->handle(Request::create('HEAD', '/health'));
        self::assertSame(200, $head->status());
        self::assertSame('', $head->body());
        // Andere Pfade ohne Schrägstrich werden weiterhin umgeleitet
        self::assertSame(301, $kernel->handle(Request::create('GET', '/kontakt'))->status());
    }
}
