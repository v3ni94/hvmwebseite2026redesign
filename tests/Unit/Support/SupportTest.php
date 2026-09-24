<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Support;

use Hvm\Support\Clock;
use Hvm\Support\Config;
use Hvm\Support\Log;
use Hvm\Support\Uuid;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function testLogRedactsEmailAndPhone(): void
    {
        $text = Log::redact('Kontakt max.mustermann@example.org, Tel. 0211 123456-78, +49 (0) 30 1234567');
        self::assertStringNotContainsString('example.org', $text);
        self::assertStringNotContainsString('123456', $text);
        self::assertStringNotContainsString('1234567', $text);
        self::assertStringContainsString('[email entfernt]', $text);
        self::assertStringContainsString('[telefon entfernt]', $text);
    }

    public function testLogKeepsDatesUuidsAndShortNumbers(): void
    {
        $uuid = '3f1c2b4a-5d6e-4f70-8a9b-0c1d2e3f4a5b';
        $text = Log::redact("Lead $uuid am 2026-09-22 10:15:00, Status 500, Zeile 123");
        self::assertStringContainsString($uuid, $text);
        self::assertStringContainsString('2026-09-22', $text);
        self::assertStringContainsString('500', $text);
    }

    public function testUuidV4(): void
    {
        $uuid = Uuid::v4();
        self::assertTrue(Uuid::isValid($uuid));
        self::assertNotSame($uuid, Uuid::v4());
    }

    public function testClockFreeze(): void
    {
        Clock::freeze('2026-07-01 12:00:00');
        self::assertSame('2026-07-01T12:00:00+00:00', Clock::now()->format(DATE_ATOM));
        self::assertSame('14:00', Clock::local()->format('H:i'));
        Clock::unfreeze();
        self::assertSame('UTC', Clock::now()->getTimezone()->getName());
    }

    public function testConfigDotNotation(): void
    {
        $config = new Config(['unternehmen' => ['anschrift' => ['ort' => 'Monheim am Rhein']], 'leer' => null]);
        self::assertSame('Monheim am Rhein', $config->get('unternehmen.anschrift.ort'));
        self::assertNull($config->get('unternehmen.fehlt'));
        self::assertSame('x', $config->get('a.b.c', 'x'));
        self::assertTrue($config->has('leer'));
        self::assertFalse($config->has('fehlt'));
    }

    public function testCompanyConfigHasNoInventedValues(): void
    {
        $config = Config::fromDirectory(dirname(__DIR__, 3) . '/config');
        self::assertSame('02431 9550300', $config->get('unternehmen.telefon')); // bestätigt 23.09.2026
        self::assertSame('02431 9550300', $config->get('unternehmen.notfall_telefon')); // bestätigt 24.09.2026
        self::assertNull($config->get('unternehmen.ust_id'));
        self::assertSame('https://portal.muellerhv.de', $config->get('unternehmen.portal_url')); // bestätigt 24.09.2026
        self::assertSame('HRB 104762', $config->get('unternehmen.hrb'));
        self::assertSame([], $config->get('kundenstimmen'));
        foreach ($config->array('standorte') as $standort) {
            self::assertArrayNotHasKey('telefon', $standort);
            self::assertNotSame('erkelenz', $standort['slug']);
        }
        foreach ($config->array('freigaben') as $freigabe) {
            self::assertSame('entwurf', $freigabe['status']);
        }
    }
}
