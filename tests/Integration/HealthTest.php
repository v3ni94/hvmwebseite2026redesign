<?php

declare(strict_types=1);

namespace Hvm\Tests\Integration;

use Hvm\Http\Request;

final class HealthTest extends IntegrationTestCase
{
    public function testReportsReachableDatabase(): void
    {
        $response = $this->kernel()->handle(Request::create('GET', '/health'));
        self::assertSame(200, $response->status());
        self::assertSame('{"status":"ok","datenbank":"ja"}', $response->body());
    }

    public function testWrongCredentialsReportDatabaseUnreachable(): void
    {
        $response = $this->kernel(['DB_PASSWORD' => 'falsch-nur-test'])->handle(Request::create('GET', '/health'));
        self::assertSame(200, $response->status());
        self::assertSame('{"status":"eingeschraenkt","datenbank":"nein"}', $response->body());
    }
}
