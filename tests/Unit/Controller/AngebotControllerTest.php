<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Controller;

use Hvm\Http\Kernel;
use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Security\SpamGuard;
use Hvm\Tests\Unit\TestCase;

/**
 * Formularstrecke ohne Datenbank: Anzeige, Vorbelegung, serverseitige Prüfung, Spam-Pfade.
 * Speichern, Rate Limit und Outbox prüft tests/Integration/AngebotFlowTest.
 */
final class AngebotControllerTest extends TestCase
{
    private function app(): Kernel
    {
        return $this->kernel('development', [
            'DB_NAME' => null,
            'APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32)),
        ]);
    }

    private function csrf(Kernel $kernel): string
    {
        $html = $kernel->handle(Request::create('GET', '/angebot/'))->body();
        self::assertSame(1, preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $m));

        return $m[1];
    }

    /**
     * @param array<string, string> $post
     */
    private function post(Kernel $kernel, array $post, ?int $tokenTime = null): Response
    {
        $post['_csrf'] ??= $this->csrf($kernel);
        $post[SpamGuard::TOKEN_FIELD] ??= $kernel->container()->get(SpamGuard::class)->issueToken('angebot', $tokenTime ?? time() - 10);
        $post[SpamGuard::HONEYPOT_FIELD] ??= '';

        return $kernel->handle(Request::create('POST', '/angebot/', $post));
    }

    public function testFormRendersWithoutDatabase(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/angebot/'));
        self::assertSame(200, $response->status());
        self::assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
        $html = $response->body();
        foreach (['art', 'strasse', 'plz', 'ort', 'baujahr', 'wohneinheiten', 'gewerbeeinheiten', 'stellplaetze', 'beginn', 'aktueller_verwalter', 'anrede', 'vorname', 'nachname', 'email', 'telefon', 'rolle', 'nachricht', 'datenschutz'] as $feld) {
            self::assertStringContainsString('name="' . $feld . '"', $html, $feld);
        }
        self::assertStringContainsString('novalidate', $html);
        self::assertMatchesRegularExpression('#<div class="c-angebot__hp" aria-hidden="true">.*?tabindex="-1" autocomplete="off">#s', $html);
        self::assertMatchesRegularExpression('#name="_zeit" value="\d+\.[a-f0-9]{64}"#', $html);
        self::assertStringContainsString('href="/datenschutz/"', $html);
        self::assertStringContainsString('[Freigabe Datenschutztext]', $html);
        self::assertStringContainsString('[Antwortzeit festlegen]', $html);
        self::assertDoesNotMatchRegularExpression('/name="datenschutz"[^>]*checked/', $html, 'kein vorangekreuztes Häkchen');
        self::assertStringNotContainsString('style="', $html);
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+="/', $html);
        self::assertMatchesRegularExpression('#<script type="module" src="/assets/build/[^"]*angebot\.js" nonce="[^"]+"></script>#', $html);
        self::assertStringNotContainsString('localStorage', $html);
    }

    public function testPrefillFromQuery(): void
    {
        $html = $this->app()->handle(Request::create(
            'GET',
            '/angebot/?art=miet&anlass=wechsel&region=duesseldorf&utm_source=google&utm_campaign=miet&gclid=Cj0KCQjw_abc-123&lp=%2Fmietverwaltung%2F',
            [],
            ['HTTP_REFERER' => 'https://www.google.de/search?q=name%40example.org']
        ))->body();
        self::assertMatchesRegularExpression('/id="feld-art-miet" name="art" value="miet" checked/', $html);
        self::assertMatchesRegularExpression('/id="feld-aktueller_verwalter-ja" name="aktueller_verwalter" value="ja" checked/', $html);
        self::assertStringContainsString('<input type="hidden" name="region" value="Düsseldorf">', $html);
        self::assertStringContainsString('<input type="hidden" name="utm_source" value="google">', $html);
        self::assertStringContainsString('<input type="hidden" name="gclid" value="Cj0KCQjw_abc-123">', $html);
        self::assertStringContainsString('<input type="hidden" name="landing_page" value="/mietverwaltung/">', $html);
        self::assertStringContainsString('<input type="hidden" name="referrer" value="www.google.de/search">', $html);
        self::assertStringNotContainsString('name%40example.org', $html, 'Query des Referrers wird nicht übernommen');
        self::assertStringNotContainsString('search?q', $html);
    }

    public function testUnknownPrefillIsIgnored(): void
    {
        $html = $this->app()->handle(Request::create('GET', '/angebot/?art=gewerbe&region=%3Cscript%3E'))->body();
        self::assertDoesNotMatchRegularExpression('/name="art" value="[a-z]+" checked/', $html);
        self::assertStringNotContainsString('name="region"', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testInvalidSubmissionShowsErrorsAtFieldsAndSummary(): void
    {
        $kernel = $this->app();
        $response = $this->post($kernel, ['art' => 'weg', 'plz' => '123', 'ort' => '<b>Monheim</b>', 'email' => 'kaputt']);
        self::assertSame(422, $response->status());
        $html = $response->body();
        self::assertStringContainsString('id="fehlerliste" role="alert"', $html);
        self::assertStringContainsString('<a href="#feld-plz" data-schritt-ziel="2">Bitte geben Sie eine fünfstellige Postleitzahl an.</a>', $html);
        self::assertStringContainsString('<a href="#feld-wohneinheiten"', $html);
        self::assertStringContainsString('<a href="#feld-email"', $html);
        self::assertMatchesRegularExpression('/id="feld-plz" name="plz"[^>]*aria-describedby="feld-plz-fehler" aria-invalid="true"/', $html);
        self::assertStringContainsString('value="&lt;b&gt;Monheim&lt;/b&gt;"', $html, 'Eingaben bleiben erhalten und werden escaped');
        self::assertStringNotContainsString('<b>Monheim</b>', $html);
        self::assertStringContainsString('data-server-fehler', $html);
    }

    public function testHoneypotRedirectsLikeSuccessWithoutStoring(): void
    {
        $response = $this->post($this->app(), ['art' => 'weg', SpamGuard::HONEYPOT_FIELD => 'https://spam.example.org']);
        self::assertSame(303, $response->status());
        self::assertSame('/angebot/danke/', $response->header('Location'));
    }

    public function testTooFastSubmissionIsTreatedAsSpam(): void
    {
        $response = $this->post($this->app(), ['art' => 'weg'], time());
        self::assertSame(303, $response->status());
    }

    public function testExpiredTokenAsksToResubmit(): void
    {
        $response = $this->post($this->app(), ['art' => 'weg', 'plz' => '40789'], time() - SpamGuard::MAX_AGE - 60);
        self::assertSame(422, $response->status());
        self::assertStringContainsString('Das Formular war zu lange geöffnet', $response->body());
        self::assertStringContainsString('value="40789"', $response->body());
    }

    public function testMissingCsrfIsRejected(): void
    {
        $response = $this->app()->handle(Request::create('POST', '/angebot/', ['art' => 'weg']));
        self::assertSame(403, $response->status());
    }

    public function testValidSubmissionWithoutDatabaseShowsTechnicalNotice(): void
    {
        $response = $this->post($this->app(), [
            'art' => 'weg', 'plz' => '40789', 'ort' => 'Monheim am Rhein', 'wohneinheiten' => '4',
            'nachname' => 'Beispiel', 'email' => 'test@example.org', 'datenschutz' => '1',
        ]);
        self::assertSame(503, $response->status());
        self::assertStringContainsString('Ihre Anfrage konnte nicht gespeichert werden', $response->body());
        self::assertStringContainsString('value="test@example.org"', $response->body());
    }

    public function testThankYouPage(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/angebot/danke/'));
        self::assertSame(200, $response->status());
        self::assertMatchesRegularExpression('#<h1>[^<]+</h1>#', $response->body());
        self::assertStringContainsString('[Antwortzeit festlegen]', $response->body());
        self::assertStringContainsString('Besichtigung und Angebot', $response->body());
    }
}
