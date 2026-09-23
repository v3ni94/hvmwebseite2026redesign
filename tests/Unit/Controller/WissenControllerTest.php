<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Controller;

use Hvm\Tests\Unit\TestCase;

final class WissenControllerTest extends TestCase
{
    public function testUebersichtRendertMitH1UndOhneEntwuerfeInProduktion(): void
    {
        $response = $this->get('/wissen/', 'production');

        self::assertSame(200, $response->status());
        self::assertMatchesRegularExpression('#<h1>[^<]+</h1>#', $response->body());
        self::assertStringNotContainsString('style="', $response->body());
    }

    public function testUebersichtFiltertNachZielgruppeUndSuchbegriff(): void
    {
        $response = $this->get('/wissen/?zielgruppe=mieter&q=schaden');

        self::assertSame(200, $response->status());
    }

    public function testUnbekannterArtikelIst404(): void
    {
        $response = $this->get('/wissen/dieser-artikel-existiert-nicht/');

        self::assertSame(404, $response->status());
    }

    public function testArtikelMitUngueltigemSlugZeichensatzIst404(): void
    {
        // Router lässt nur [a-z0-9-]+ zu, ein Punkt darf nicht als Artikelpfad durchgereicht werden.
        $response = $this->get('/wissen/../geheim/');

        self::assertSame(404, $response->status());
    }
}
