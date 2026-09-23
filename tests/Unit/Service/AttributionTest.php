<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Service;

use Hvm\Service\Attribution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttributionTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, string>, ?string, ?string, string}>
     */
    public static function quellen(): iterable
    {
        yield 'utm_source hat Vorrang' => [['utm_source' => 'Newsletter Herbst'], 'abcdefghijk123', 'www.google.de', 'newsletter_herbst'];
        yield 'gclid ohne utm' => [[], 'abcdefghijk123', 'www.google.de', 'google_ads'];
        yield 'Google organisch' => [[], null, 'www.google.de', 'organisch'];
        yield 'Google mit Pfad' => [[], null, 'www.google.com/search', 'organisch'];
        yield 'Bing' => [[], null, 'www.bing.com', 'organisch'];
        yield 'DuckDuckGo' => [[], null, 'duckduckgo.com', 'organisch'];
        yield 'Ecosia' => [[], null, 'www.ecosia.org', 'organisch'];
        yield 'fremder Referrer' => [[], null, 'www.immobilienportal.example.org/liste', 'verweis'];
        yield 'google im Namen, keine Suchmaschine' => [[], null, 'google.example.org', 'verweis'];
        yield 'direkt' => [[], null, null, 'direkt'];
        yield 'leere utm_source' => [['utm_source' => '  '], null, null, 'direkt'];
    }

    /**
     * @param array<string, string> $utm
     */
    #[DataProvider('quellen')]
    public function testDeriveSource(array $utm, ?string $gclid, ?string $referrer, string $erwartet): void
    {
        self::assertSame($erwartet, Attribution::deriveSource($utm, $gclid, $referrer));
    }

    public function testUtmIsCleanedAndLimited(): void
    {
        $utm = Attribution::utm([
            'utm_source' => " google\n",
            'utm_medium' => ['array'],
            'utm_campaign' => str_repeat('x', 200),
            'andere' => 'ignoriert',
        ]);
        self::assertSame('google', $utm['utm_source']);
        self::assertArrayNotHasKey('utm_medium', $utm);
        self::assertSame(150, mb_strlen($utm['utm_campaign']));
        self::assertArrayNotHasKey('andere', $utm);
    }

    public function testReferrerIsReducedToHostAndPath(): void
    {
        self::assertSame(
            'www.portal.example.org/suche/ergebnis',
            Attribution::referrerForStorage('https://www.portal.example.org/suche/ergebnis?email=a%40example.org#x', 'www.muellerhv.de')
        );
        self::assertNull(Attribution::referrerForStorage('https://www.muellerhv.de/weg-verwaltung/', 'www.muellerhv.de'));
        self::assertNull(Attribution::referrerForStorage('https://muellerhv.de/', ['localhost:8081', 'www.muellerhv.de']));
        self::assertNull(Attribution::referrerForStorage('kein referrer', 'www.muellerhv.de'));
        self::assertSame('www.google.de', Attribution::referrerHost('https://www.google.de/search?q=hausverwaltung', 'www.muellerhv.de'));
    }

    public function testInternalPath(): void
    {
        self::assertSame('/weg-verwaltung/', Attribution::internalPath('/weg-verwaltung/?utm_source=x'));
        self::assertNull(Attribution::internalPath('//evil.example.org/'));
        self::assertNull(Attribution::internalPath('https://evil.example.org/'));
        self::assertNull(Attribution::internalPath('/<script>/'));
    }

    public function testGclid(): void
    {
        self::assertSame('Cj0KCQjw_abc-123', Attribution::gclid('Cj0KCQjw_abc-123'));
        self::assertNull(Attribution::gclid('kurz'));
        self::assertNull(Attribution::gclid('mit leerzeichen und so weiter'));
    }

    public function testForwardParams(): void
    {
        $params = Attribution::forwardParams(
            ['utm_source' => 'google', 'utm_campaign' => 'weg', 'gclid' => 'Cj0KCQjw_abc-123', 'foo' => 'bar'],
            '/weg-verwaltung/',
            'https://www.bing.com/search?q=x',
            'www.muellerhv.de'
        );
        self::assertSame([
            'utm_source' => 'google',
            'utm_campaign' => 'weg',
            'gclid' => 'Cj0KCQjw_abc-123',
            'lp' => '/weg-verwaltung/',
            'ref' => 'www.bing.com',
        ], $params);

        $weiter = Attribution::forwardParams(['lp' => '/verwalterwechsel/', 'ref' => 'www.google.de'], '/weg-verwaltung/', 'https://www.muellerhv.de/verwalterwechsel/', 'www.muellerhv.de');
        self::assertSame(['lp' => '/verwalterwechsel/', 'ref' => 'www.google.de'], $weiter, 'erster Landing-Pfad und externer Referrer bleiben erhalten');
    }

    public function testBuildUrl(): void
    {
        self::assertSame('/angebot/', Attribution::buildUrl('/angebot/', []));
        self::assertSame('/angebot/?art=weg&lp=%2Fweg-verwaltung%2F', Attribution::buildUrl('/angebot/', ['art' => 'weg', 'lp' => '/weg-verwaltung/', 'leer' => '']));
        self::assertSame('/angebot/?anlass=wechsel&art=weg', Attribution::buildUrl('/angebot/?anlass=wechsel', ['art' => 'weg']));
    }
}
