<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Controller;

use Hvm\Tests\Unit\TestCase;

final class FaktenControllerTest extends TestCase
{
    public function testFaktenseiteZeigtBelegteAngabenMitStand(): void
    {
        $response = $this->get('/fakten/', 'production');
        self::assertSame(200, $response->status());
        $html = $response->body();

        self::assertSame(1, preg_match_all('#<h1>[^<]+</h1>#', $html), 'genau eine h1 ohne Attribute');
        self::assertStringContainsString('<title>Die HVM in Zahlen und Fakten | Hausverwaltung Müller GmbH</title>', $html);
        self::assertStringContainsString('Amtsgericht Düsseldorf, HRB 104762', $html);
        self::assertStringContainsString('Timo Müller', $html);
        self::assertStringContainsString('04.03.2020', $html);
        self::assertStringContainsString('869 Verwaltungseinheiten in 67 Objekten', $html);
        self::assertStringContainsString('01.07.2026', $html);
        self::assertStringContainsString('VZIV', $html);
        self::assertStringContainsString('IVD', $html);
        self::assertStringContainsString('24/7-Notdienst', $html);
        self::assertStringContainsString('keine Aufnahmegebühr', $html);
        self::assertStringContainsString('<dl class="c-angaben">', $html);
        self::assertStringContainsString('<table class="c-tabelle">', $html);
        self::assertStringContainsString('Stand 23.09.2026', $html);
        self::assertStringNotContainsString('style="', $html);
        self::assertStringNotContainsString('[bestätigen]', $html);
    }

    public function testFaktenseiteHatOrganisationsSchema(): void
    {
        $html = $this->get('/fakten/', 'production')->body();
        preg_match_all('#<script type="application/ld\+json" nonce="[^"]+">(.*?)</script>#s', $html, $m);
        $typen = [];
        foreach ($m[1] as $json) {
            $daten = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $typen[] = is_array($daten['@type']) ? implode('+', $daten['@type']) : $daten['@type'];
            if (($daten['@type'] ?? null) === ['Organization', 'RealEstateAgent']) {
                self::assertSame('2020-03-04', $daten['foundingDate']);
                self::assertSame('+49 2431 9550300', $daten['telephone']);
            }
        }
        self::assertContains('Organization+RealEstateAgent', $typen);
        self::assertContains('AboutPage', $typen);
        self::assertContains('BreadcrumbList', $typen);
    }

    public function testFaktenseiteImFooterUndAufUeberUns(): void
    {
        self::assertStringContainsString('<a href="/fakten/">Zahlen und Fakten</a>', $this->get('/')->body());
        self::assertStringContainsString('href="/fakten/"', $this->get('/ueber-uns/')->body());
    }
}
