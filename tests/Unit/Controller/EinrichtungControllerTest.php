<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Controller;

use Hvm\Http\Kernel;
use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Tests\Unit\TestCase;

/**
 * Web-Einrichtung /_einrichtung/ ohne Datenbank: Zugang, Token, Rate Limit, CSRF, Hash-Hilfe, Abschluss.
 * Migration und Admin-Anlage: tests/Integration/EinrichtungFlowTest.php.
 */
final class EinrichtungControllerTest extends TestCase
{
    private const TOKEN = 'fiktiver-einrichtungs-token-0123456789abcdef';

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/hvm-einrichtung-' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->storage);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * @param array<string, string|null> $env
     */
    private function setupKernel(string $appEnv = 'staging', ?string $token = self::TOKEN, array $env = []): Kernel
    {
        $kernel = $this->kernel($appEnv, array_merge([
            'SETUP_TOKEN' => $token,
            'DB_HOST' => null,
            'DB_NAME' => null,
        ], $env));
        $kernel->config()->set('app.setup_storage', $this->storage);

        return $kernel;
    }

    private static function csrf(Response $response): string
    {
        preg_match('/name="_csrf" value="([^"]+)"/', $response->body(), $m);
        self::assertNotEmpty($m[1] ?? '', 'CSRF-Feld fehlt');

        return $m[1];
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(Kernel $kernel, array $fields, string $ip = '203.0.113.10'): Response
    {
        $csrf = self::csrf($kernel->handle(Request::create('GET', '/_einrichtung/', [], ['REMOTE_ADDR' => $ip])));

        return $kernel->handle(Request::create('POST', '/_einrichtung/', $fields + ['_csrf' => $csrf], ['REMOTE_ADDR' => $ip]));
    }

    private function login(Kernel $kernel): void
    {
        $response = $this->post($kernel, ['aktion' => 'anmelden', 'token' => self::TOKEN]);
        self::assertSame(303, $response->status());
        self::assertSame('/_einrichtung/', $response->header('Location'));
    }

    public function testNotFoundWithoutToken(): void
    {
        foreach (['staging', 'production', 'development'] as $env) {
            $response = $this->setupKernel($env, null)->handle(Request::create('GET', '/_einrichtung/'));
            self::assertSame(404, $response->status(), $env);
        }
    }

    public function testNotFoundWithShortToken(): void
    {
        $response = $this->setupKernel('staging', str_repeat('k', 31))->handle(Request::create('GET', '/_einrichtung/'));
        self::assertSame(404, $response->status());
    }

    public function testNotFoundWhenLockExists(): void
    {
        file_put_contents($this->storage . '/setup.lock', 'abgeschlossen');
        $kernel = $this->setupKernel();
        self::assertSame(404, $kernel->handle(Request::create('GET', '/_einrichtung/'))->status());
    }

    public function testLockAlsoBlocksPostWithValidCsrf(): void
    {
        $kernel = $this->setupKernel();
        $csrf = self::csrf($kernel->handle(Request::create('GET', '/_einrichtung/')));
        file_put_contents($this->storage . '/setup.lock', 'abgeschlossen');
        $response = $kernel->handle(Request::create('POST', '/_einrichtung/', ['_csrf' => $csrf, 'aktion' => 'anmelden', 'token' => self::TOKEN]));
        self::assertSame(404, $response->status());
    }

    public function testLoginPageWithTokenInProductionToo(): void
    {
        foreach (['staging', 'production'] as $env) {
            $response = $this->setupKernel($env)->handle(Request::create('GET', '/_einrichtung/'));
            self::assertSame(200, $response->status(), $env);
            self::assertStringContainsString('Einrichtungs-Token', $response->body());
            self::assertStringNotContainsString(self::TOKEN, $response->body());
            self::assertSame('noindex, nofollow', $response->header('X-Robots-Tag'));
            self::assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
            self::assertStringNotContainsString('unsafe-inline', (string) $response->header('Content-Security-Policy'));
            self::assertStringNotContainsString('style="', $response->body());
        }
    }

    public function testWrongTokenIsRejected(): void
    {
        $kernel = $this->setupKernel();
        $response = $this->post($kernel, ['aktion' => 'anmelden', 'token' => 'falscher-token-falscher-token-falscher']);
        self::assertSame(422, $response->status());
        self::assertStringContainsString('Der Token ist nicht korrekt.', $response->body());

        // ohne Anmeldung keine Aktionen
        $response = $this->post($kernel, ['aktion' => 'hash', 'benutzer' => 'pruefer', 'passwort' => 'Fiktives-Passwort-123']);
        self::assertSame(403, $response->status());
        self::assertStringNotContainsString('STAGING_BASIC_AUTH=', $response->body());
    }

    public function testPostWithoutCsrfIsRejected(): void
    {
        $response = $this->setupKernel()->handle(Request::create('POST', '/_einrichtung/', ['aktion' => 'anmelden', 'token' => self::TOKEN]));
        self::assertSame(403, $response->status());
    }

    public function testRateLimitAfterFiveAttempts(): void
    {
        $kernel = $this->setupKernel();
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(422, $this->post($kernel, ['aktion' => 'anmelden', 'token' => 'falsch-' . $i])->status());
        }
        // auch der richtige Token wird jetzt abgewiesen
        $response = $this->post($kernel, ['aktion' => 'anmelden', 'token' => self::TOKEN]);
        self::assertSame(429, $response->status());
        self::assertNotNull($response->header('Retry-After'));

        // andere IP-Adresse ist nicht betroffen
        self::assertSame(303, $this->post($kernel, ['aktion' => 'anmelden', 'token' => self::TOKEN], '203.0.113.99')->status());

        // Zähler liegt als Datei ohne Klartext-IP vor
        $files = glob($this->storage . '/ratelimit/*.json') ?: [];
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertStringNotContainsString('203.0.113', basename($file) . file_get_contents($file));
        }
    }

    public function testOverviewShowsDiagnoseWithoutSecrets(): void
    {
        $kernel = $this->setupKernel();
        $this->login($kernel);
        $response = $kernel->handle(Request::create('GET', '/_einrichtung/'));
        self::assertSame(200, $response->status());
        $html = $response->body();
        self::assertStringContainsString('1. Diagnose', $html);
        self::assertStringContainsString('APP_KEY gültig', $html);
        self::assertStringContainsString('Keine Datenbankverbindung', $html);
        self::assertStringNotContainsString(self::TOKEN, $html);
        self::assertStringNotContainsString(base64_encode(str_repeat('t', 32)), $html);
    }

    public function testChangedTokenEndsSession(): void
    {
        $kernel = $this->setupKernel();
        $this->login($kernel);
        $kernel->config()->set('app.setup_token', 'neuer-fiktiver-token-0123456789abcdefghij');
        $response = $kernel->handle(Request::create('GET', '/_einrichtung/'));
        self::assertStringContainsString('Einrichtungs-Token', $response->body());
        self::assertStringNotContainsString('1. Diagnose', $response->body());
    }

    public function testHashHelperProducesVerifiableLine(): void
    {
        $kernel = $this->setupKernel();
        $this->login($kernel);
        $response = $this->post($kernel, ['aktion' => 'hash', 'benutzer' => 'pruefer', 'passwort' => 'Fiktives-Passwort-123']);
        self::assertSame(200, $response->status());
        self::assertMatchesRegularExpression("/STAGING_BASIC_AUTH=(?:'|&#039;)pruefer:([^'&]+)(?:'|&#039;)/", $response->body());
        preg_match("/STAGING_BASIC_AUTH=(?:'|&#039;)pruefer:([^'&]+)(?:'|&#039;)/", $response->body(), $m);
        self::assertTrue(password_verify('Fiktives-Passwort-123', html_entity_decode($m[1], ENT_QUOTES)));

        self::assertSame(422, $this->post($kernel, ['aktion' => 'hash', 'benutzer' => 'a:b', 'passwort' => 'Fiktives-Passwort-123'])->status());
        self::assertSame(422, $this->post($kernel, ['aktion' => 'hash', 'benutzer' => 'pruefer', 'passwort' => 'kurz'])->status());
    }

    public function testFinishWritesLockAndThen404(): void
    {
        $kernel = $this->setupKernel();
        $this->login($kernel);
        self::assertSame(422, $this->post($kernel, ['aktion' => 'abschliessen'])->status());
        self::assertFileDoesNotExist($this->storage . '/setup.lock');

        $response = $this->post($kernel, ['aktion' => 'abschliessen', 'bestaetigung' => 'ja']);
        self::assertSame(200, $response->status());
        self::assertStringContainsString('Einrichtung abgeschlossen', $response->body());
        self::assertFileExists($this->storage . '/setup.lock');

        self::assertSame(404, $kernel->handle(Request::create('GET', '/_einrichtung/'))->status());
    }

    public function testNeverListedInSitemapRobotsOrLlms(): void
    {
        foreach (['production', 'staging'] as $env) {
            $kernel = $this->setupKernel($env, self::TOKEN, ['SHOW_DRAFTS' => 'true']);
            foreach (['/sitemap.xml', '/robots.txt', '/llms.txt'] as $path) {
                $response = $kernel->handle(Request::create('GET', $path));
                self::assertStringNotContainsString('_einrichtung', $response->body(), $env . ' ' . $path);
            }
        }
    }
}
