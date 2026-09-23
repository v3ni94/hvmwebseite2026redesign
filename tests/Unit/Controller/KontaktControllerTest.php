<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Controller;

use Hvm\Http\Kernel;
use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Security\SpamGuard;
use Hvm\Tests\Unit\TestCase;

/**
 * Kontaktformular ohne Datenbank: Anzeige, Vorbelegung, serverseitige Prüfung, Spam-Pfade.
 * Speichern, Rate Limit und Outbox prüft tests/Integration/KontaktFlowTest.
 */
final class KontaktControllerTest extends TestCase
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
        $html = $kernel->handle(Request::create('GET', '/kontakt/'))->body();
        self::assertSame(1, preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $m));

        return $m[1];
    }

    /**
     * @param array<string, string> $post
     */
    private function post(Kernel $kernel, array $post, ?int $tokenTime = null): Response
    {
        $post['_csrf'] ??= $this->csrf($kernel);
        $post[SpamGuard::TOKEN_FIELD] ??= $kernel->container()->get(SpamGuard::class)->issueToken('kontakt', $tokenTime ?? time() - 10);
        $post[SpamGuard::HONEYPOT_FIELD] ??= '';

        return $kernel->handle(Request::create('POST', '/kontakt/', $post));
    }

    public function testFormRendersWithoutDatabase(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/kontakt/'));
        self::assertSame(200, $response->status());
        self::assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
        $html = $response->body();
        foreach (['anliegen', 'name', 'email', 'telefon', 'nachricht', 'datenschutz'] as $feld) {
            self::assertStringContainsString('name="' . $feld . '"', $html, $feld);
        }
        self::assertStringContainsString('novalidate', $html);
        self::assertMatchesRegularExpression('#<div class="c-formular__hp" aria-hidden="true">.*?tabindex="-1" autocomplete="off">#s', $html);
        self::assertMatchesRegularExpression('#name="_zeit" value="\d+\.[a-f0-9]{64}"#', $html);
        self::assertStringContainsString('href="/datenschutz/"', $html);
        self::assertStringContainsString('[Freigabe Datenschutztext]', $html);
        self::assertDoesNotMatchRegularExpression('/name="datenschutz"[^>]*checked/', $html, 'kein vorangekreuztes Häkchen');
        self::assertStringNotContainsString('style="', $html);
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+="/', $html);
        self::assertStringNotContainsString('localStorage', $html);
    }

    public function testPrefillFromQuery(): void
    {
        $html = $this->app()->handle(Request::create('GET', '/kontakt/?anliegen=vermietung&region=duesseldorf'))->body();
        self::assertMatchesRegularExpression('/id="feld-anliegen"[^>]*>.*?<option value="vermietung" selected>/s', $html);
        self::assertStringContainsString('<input type="hidden" name="region" value="Düsseldorf">', $html);
    }

    public function testUnknownAnliegenPrefillIsIgnored(): void
    {
        $html = $this->app()->handle(Request::create('GET', '/kontakt/?anliegen=<script>'))->body();
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testVerwaltungAnliegenShowsHintToOfferForm(): void
    {
        $html = $this->app()->handle(Request::create('GET', '/kontakt/?anliegen=verwaltung'))->body();
        self::assertStringContainsString('/angebot/', $html);
    }

    public function testInvalidSubmissionShowsErrorsAtFieldsAndSummary(): void
    {
        $kernel = $this->app();
        $response = $this->post($kernel, ['anliegen' => 'allgemein', 'email' => 'kaputt', 'nachricht' => 'Test']);
        self::assertSame(422, $response->status());
        $html = $response->body();
        self::assertStringContainsString('id="kontakt-fehlerliste" role="alert"', $html);
        self::assertStringContainsString('<a href="#feld-email">', $html);
        self::assertMatchesRegularExpression('/id="feld-email" name="email"[^>]*aria-describedby="feld-email-fehler" aria-invalid="true"/', $html);
        self::assertStringContainsString('value="kaputt"', $html, 'Eingaben bleiben erhalten');
    }

    public function testHoneypotRedirectsLikeSuccessWithoutStoring(): void
    {
        $response = $this->post($this->app(), ['anliegen' => 'allgemein', SpamGuard::HONEYPOT_FIELD => 'https://spam.example.org']);
        self::assertSame(303, $response->status());
        self::assertSame('/kontakt/danke/', $response->header('Location'));
    }

    public function testTooFastSubmissionIsTreatedAsSpam(): void
    {
        $response = $this->post($this->app(), ['anliegen' => 'allgemein'], time());
        self::assertSame(303, $response->status());
    }

    public function testExpiredTokenAsksToResubmit(): void
    {
        $response = $this->post($this->app(), ['anliegen' => 'allgemein', 'nachricht' => 'Test'], time() - SpamGuard::MAX_AGE - 60);
        self::assertSame(422, $response->status());
        self::assertStringContainsString('Das Formular war zu lange geöffnet', $response->body());
        self::assertMatchesRegularExpression('#<textarea[^>]*name="nachricht"[^>]*>Test</textarea>#', $response->body());
    }

    public function testMissingCsrfIsRejected(): void
    {
        $response = $this->app()->handle(Request::create('POST', '/kontakt/', ['anliegen' => 'allgemein']));
        self::assertSame(403, $response->status());
    }

    public function testValidSubmissionWithoutDatabaseShowsTechnicalNotice(): void
    {
        $response = $this->post($this->app(), [
            'anliegen' => 'allgemein', 'name' => 'Erika Beispiel', 'email' => 'test@example.org',
            'nachricht' => 'Testnachricht', 'datenschutz' => '1',
        ]);
        self::assertSame(503, $response->status());
        self::assertStringContainsString('Ihre Nachricht konnte nicht gespeichert werden', $response->body());
        self::assertStringContainsString('value="test@example.org"', $response->body());
    }

    public function testThankYouPage(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/kontakt/danke/'));
        self::assertSame(200, $response->status());
        self::assertMatchesRegularExpression('#<h1>[^<]+</h1>#', $response->body());
        self::assertStringContainsString('[Antwortzeit festlegen]', $response->body());
    }
}
