<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Http;

use Hvm\Http\Middleware\StagingBasicAuth;
use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Tests\Unit\TestCase;

/**
 * Staging-Schutz in der Anwendung (Webhosting ohne Traefik). Nur fiktive Zugangsdaten.
 */
final class StagingBasicAuthTest extends TestCase
{
    private const USER = 'pruefer';
    private const PASSWORD = 'Fiktives-Staging-Passwort';

    private static ?string $hash = null;

    private static function credentials(): string
    {
        self::$hash ??= password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);

        return self::USER . ':' . self::$hash;
    }

    /**
     * @param array<string, string> $server
     */
    private function call(string $env, ?string $credentials, array $server = [], string $path = '/'): Response
    {
        return (new StagingBasicAuth($env, $credentials))
            ->process(Request::create('GET', $path, [], $server), static fn (): Response => Response::html('inhalt'));
    }

    private static function basic(string $user, string $password): string
    {
        return 'Basic ' . base64_encode($user . ':' . $password);
    }

    public function testInactiveOutsideStagingOrWithoutCredentials(): void
    {
        self::assertSame(200, $this->call('production', self::credentials())->status());
        self::assertSame(200, $this->call('development', self::credentials())->status());
        self::assertSame(200, $this->call('staging', null)->status());
        self::assertSame(200, $this->call('staging', '')->status());
    }

    public function testMissingCredentialsGet401WithChallenge(): void
    {
        $response = $this->call('staging', self::credentials());
        self::assertSame(401, $response->status());
        self::assertStringStartsWith('Basic realm="', (string) $response->header('WWW-Authenticate'));
        self::assertSame('noindex, nofollow', $response->header('X-Robots-Tag'));
        self::assertSame('no-store', $response->header('Cache-Control'));
        self::assertStringNotContainsString('inhalt', $response->body());
    }

    public function testValidCredentialsViaAuthorizationHeaderPass(): void
    {
        $response = $this->call('staging', self::credentials(), ['HTTP_AUTHORIZATION' => self::basic(self::USER, self::PASSWORD)]);
        self::assertSame(200, $response->status());
        self::assertSame('inhalt', $response->body());
    }

    public function testRedirectedAuthorizationHeaderFromHtaccessPasses(): void
    {
        // PHP-FPM hinter Apache: RewriteRule mit E=HTTP_AUTHORIZATION kommt nach interner Umleitung als REDIRECT_ an
        $response = $this->call('staging', self::credentials(), ['REDIRECT_HTTP_AUTHORIZATION' => self::basic(self::USER, self::PASSWORD)]);
        self::assertSame(200, $response->status());
    }

    public function testPhpAuthUserPasses(): void
    {
        $response = $this->call('staging', self::credentials(), ['PHP_AUTH_USER' => self::USER, 'PHP_AUTH_PW' => self::PASSWORD]);
        self::assertSame(200, $response->status());
    }

    public function testWrongPasswordOrUserIsRejected(): void
    {
        self::assertSame(401, $this->call('staging', self::credentials(), ['HTTP_AUTHORIZATION' => self::basic(self::USER, 'falsch')])->status());
        self::assertSame(401, $this->call('staging', self::credentials(), ['HTTP_AUTHORIZATION' => self::basic('anderer', self::PASSWORD)])->status());
        self::assertSame(401, $this->call('staging', self::credentials(), ['HTTP_AUTHORIZATION' => 'Basic %%%'])->status());
        self::assertSame(401, $this->call('staging', self::credentials(), ['HTTP_AUTHORIZATION' => 'Bearer abc'])->status());
    }

    public function testHealthStaysOpen(): void
    {
        self::assertSame(200, $this->call('staging', self::credentials(), [], '/health')->status());
    }

    public function testPlainTextValueLocksInsteadOfOpening(): void
    {
        // Klartext statt Hash: sicher gesperrt, auch mit "passendem" Passwort
        $response = $this->call('staging', 'pruefer:klartext', ['HTTP_AUTHORIZATION' => self::basic('pruefer', 'klartext')]);
        self::assertSame(401, $response->status());
    }

    public function testApr1HashIsLeftToUpstreamProxy(): void
    {
        // Docker-Staging: Traefik prüft htpasswd (apr1), die Anwendung fragt nicht ein zweites Mal
        $middleware = new StagingBasicAuth('staging', 'pruefer:$apr1$abcdefgh$0123456789abcdefghijkl');
        self::assertFalse($middleware->isActive());
        self::assertSame(200, $this->call('staging', 'pruefer:$apr1$abcdefgh$0123456789abcdefghijkl')->status());
    }

    public function testKernelEnforcesInStagingAndKeepsRobotsHeader(): void
    {
        $kernel = $this->kernel('staging', ['STAGING_BASIC_AUTH' => self::credentials()]);
        $denied = $kernel->handle(Request::create('GET', '/'));
        self::assertSame(401, $denied->status());
        self::assertSame('noindex, nofollow', $denied->header('X-Robots-Tag'));
        self::assertNotNull($denied->header('Content-Security-Policy'));

        $allowed = $kernel->handle(Request::create('GET', '/', [], ['HTTP_AUTHORIZATION' => self::basic(self::USER, self::PASSWORD)]));
        self::assertSame(200, $allowed->status());
        self::assertSame('noindex, nofollow', $allowed->header('X-Robots-Tag'));

        self::assertSame(200, $this->kernel('production', ['STAGING_BASIC_AUTH' => self::credentials()])->handle(Request::create('GET', '/'))->status());
    }

    public function testPublicHtaccessPassesAuthorizationHeader(): void
    {
        $htaccess = (string) file_get_contents(self::basePath() . '/public/.htaccess');
        self::assertStringContainsString('RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]', $htaccess);
    }
}
