<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Controller;

use Hvm\Http\Kernel;
use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Security\SpamGuard;
use Hvm\Tests\Unit\TestCase;

/**
 * Bewerbungsformular ohne Datenbank: Anzeige, serverseitige Prüfung, Spam-Pfade, Upload-Fehler.
 * Speichern, Verschlüsselung und Outbox prüft tests/Integration/BewerbungFlowTest.
 */
final class BewerbungControllerTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    private function app(): Kernel
    {
        return $this->kernel('development', [
            'DB_NAME' => null,
            'APP_KEY' => 'base64:' . base64_encode(str_repeat('k', 32)),
        ]);
    }

    private function csrf(Kernel $kernel): string
    {
        $html = $kernel->handle(Request::create('GET', '/karriere/bewerbung/'))->body();
        self::assertSame(1, preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $m));

        return $m[1];
    }

    private function pdfFile(): string
    {
        $path = sys_get_temp_dir() . '/hvm-bewerbung-test-' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($path, "%PDF-1.4\nFiktiver Testinhalt.");
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param array<string, string>     $post
     * @param array<string, mixed>|null $datei
     */
    private function post(Kernel $kernel, array $post, ?array $datei = null, ?int $tokenTime = null): Response
    {
        $post['_csrf'] ??= $this->csrf($kernel);
        $post[SpamGuard::TOKEN_FIELD] ??= $kernel->container()->get(SpamGuard::class)->issueToken('bewerbung', $tokenTime ?? time() - 10);
        $post[SpamGuard::HONEYPOT_FIELD] ??= '';
        $files = $datei === null ? [] : ['datei' => $datei];

        $request = new Request('POST', '/karriere/bewerbung/', [], $post, [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/karriere/bewerbung/',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'localhost',
        ], [], $files);

        return $kernel->handle($request);
    }

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private function validDatei(): array
    {
        $path = $this->pdfFile();

        return ['name' => 'lebenslauf.pdf', 'type' => 'application/pdf', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
    }

    public function testFormRendersWithoutDatabase(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/karriere/bewerbung/'));
        self::assertSame(200, $response->status());
        self::assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
        $html = $response->body();
        foreach (['stelle', 'name', 'email', 'telefon', 'nachricht', 'einwilligung'] as $feld) {
            self::assertStringContainsString('name="' . $feld . '"', $html, $feld);
        }
        self::assertStringContainsString('name="datei"', $html);
        self::assertStringContainsString('type="file"', $html);
        self::assertStringContainsString('accept="application/pdf,.pdf"', $html);
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertStringContainsString('novalidate', $html);
        self::assertMatchesRegularExpression('#<div class="c-formular__hp" aria-hidden="true">.*?tabindex="-1" autocomplete="off">#s', $html);
        self::assertMatchesRegularExpression('#name="_zeit" value="\d+\.[a-f0-9]{64}"#', $html);
        self::assertStringContainsString('href="/datenschutz/"', $html);
        self::assertStringContainsString('[Freigabe Datenschutztext]', $html);
        self::assertDoesNotMatchRegularExpression('/name="einwilligung"[^>]*checked/', $html, 'kein vorangekreuztes Häkchen');
        self::assertStringNotContainsString('style="', $html);
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+="/', $html);
        self::assertStringNotContainsString('localStorage', $html);
    }

    public function testMissingFileShowsErrorEvenWithValidFields(): void
    {
        $kernel = $this->app();
        $response = $this->post($kernel, ['name' => 'Max Mustermann', 'email' => 'max@example.org', 'einwilligung' => '1'], null);
        self::assertSame(422, $response->status());
        $html = $response->body();
        self::assertStringContainsString('id="bewerbung-fehlerliste" role="alert"', $html);
        self::assertStringContainsString('<a href="#feld-datei">', $html);
        self::assertStringContainsString('value="Max Mustermann"', $html, 'übrige Angaben bleiben erhalten');
    }

    public function testWrongFileTypeIsRejected(): void
    {
        $path = sys_get_temp_dir() . '/hvm-bewerbung-test-' . bin2hex(random_bytes(8)) . '.txt';
        file_put_contents($path, 'Kein PDF.');
        $this->tempFiles[] = $path;
        $datei = ['name' => 'lebenslauf.txt', 'type' => 'text/plain', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];

        $response = $this->post($this->app(), ['name' => 'Max Mustermann', 'email' => 'max@example.org', 'einwilligung' => '1'], $datei);
        self::assertSame(422, $response->status());
        self::assertStringContainsString('.pdf', $response->body());
    }

    public function testInvalidFieldsAndFileErrorsAreShownTogether(): void
    {
        $path = sys_get_temp_dir() . '/hvm-bewerbung-test-' . bin2hex(random_bytes(8)) . '.txt';
        file_put_contents($path, 'Kein PDF.');
        $this->tempFiles[] = $path;
        $datei = ['name' => 'lebenslauf.txt', 'type' => 'text/plain', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];

        $response = $this->post($this->app(), ['email' => 'kaputt', 'einwilligung' => '1'], $datei);
        self::assertSame(422, $response->status());
        $html = $response->body();
        self::assertStringContainsString('<a href="#feld-name">', $html);
        self::assertStringContainsString('<a href="#feld-email">', $html);
        self::assertStringContainsString('<a href="#feld-datei">', $html);
    }

    public function testHoneypotRedirectsLikeSuccessWithoutStoring(): void
    {
        $response = $this->post($this->app(), [SpamGuard::HONEYPOT_FIELD => 'https://spam.example.org'], $this->validDatei());
        self::assertSame(303, $response->status());
        self::assertSame('/karriere/bewerbung/danke/', $response->header('Location'));
    }

    public function testTooFastSubmissionIsTreatedAsSpam(): void
    {
        $response = $this->post($this->app(), [], $this->validDatei(), time());
        self::assertSame(303, $response->status());
    }

    public function testExpiredTokenAsksToResubmit(): void
    {
        $response = $this->post($this->app(), ['name' => 'Max Mustermann', 'email' => 'max@example.org', 'einwilligung' => '1'], $this->validDatei(), time() - SpamGuard::MAX_AGE - 60);
        self::assertSame(422, $response->status());
        self::assertStringContainsString('Das Formular war zu lange geöffnet', $response->body());
        self::assertStringContainsString('value="Max Mustermann"', $response->body());
    }

    public function testMissingCsrfIsRejected(): void
    {
        $response = $this->app()->handle(Request::create('POST', '/karriere/bewerbung/', ['name' => 'Max Mustermann']));
        self::assertSame(403, $response->status());
    }

    public function testValidSubmissionWithoutDatabaseShowsTechnicalNotice(): void
    {
        $response = $this->post($this->app(), ['name' => 'Max Mustermann', 'email' => 'max@example.org', 'einwilligung' => '1'], $this->validDatei());
        self::assertSame(503, $response->status());
        self::assertStringContainsString('Ihre Bewerbung konnte nicht gespeichert werden', $response->body());
        self::assertStringContainsString('value="Max Mustermann"', $response->body());
    }

    public function testThankYouPage(): void
    {
        $response = $this->app()->handle(Request::create('GET', '/karriere/bewerbung/danke/'));
        self::assertSame(200, $response->status());
        self::assertMatchesRegularExpression('#<h1>[^<]+</h1>#', $response->body());
        self::assertStringContainsString('[Antwortzeit festlegen]', $response->body());
    }
}
