<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Http;

use Hvm\Http\Kernel;
use Hvm\Http\Middleware\Csrf;
use Hvm\Http\Middleware\ErrorHandler;
use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Http\RequestContext;
use Hvm\Http\Router;
use Hvm\Http\Session;
use Hvm\Support\Env;
use Hvm\Support\Log;
use Hvm\Tests\Unit\Fixtures\ThrowingController;
use Hvm\Tests\Unit\TestCase;

final class KernelTest extends TestCase
{
    private function logDir(): string
    {
        return sys_get_temp_dir() . '/hvm-test-logs-' . getmypid();
    }

    public function testErrorHandlerHidesDetailsInProduction(): void
    {
        $handler = new ErrorHandler(new Log($this->logDir()), true);
        $response = $handler->process(Request::create('GET', '/'), static function (): Response {
            throw new \RuntimeException('Geheime Details max.mustermann@example.org');
        });

        self::assertSame(500, $response->status());
        self::assertStringNotContainsString('Geheime Details', $response->body());
        self::assertStringNotContainsString('RuntimeException', $response->body());
        self::assertSame('0', ini_get('display_errors'));

        $log = (string) file_get_contents($this->logDir() . '/app.log');
        self::assertStringContainsString('Geheime Details', $log);
        self::assertStringNotContainsString('max.mustermann@example.org', $log);
        ini_set('display_errors', '1');
    }

    public function testKernelRendersGeneric500InProduction(): void
    {
        $kernel = $this->kernel('production');
        $kernel->container()->get(Router::class)->add('GET', '/kaputt/', [ThrowingController::class, 'show']);
        $response = $kernel->handle(Request::create('GET', '/kaputt/'));
        ini_set('display_errors', '1');

        self::assertSame(500, $response->status());
        self::assertStringContainsString('Vorübergehende Störung', $response->body());
        self::assertStringNotContainsString('Interner Fehler', $response->body());
        self::assertNotNull($response->header('Content-Security-Policy'));
        self::assertNotNull($response->header('Strict-Transport-Security'));
    }

    public function testErrorHandlerShowsDetailsInDevelopment(): void
    {
        $handler = new ErrorHandler(new Log($this->logDir()), false);
        $response = $handler->process(Request::create('GET', '/'), static function (): Response {
            throw new \RuntimeException('Sichtbar <b>escaped</b>');
        });
        self::assertSame(500, $response->status());
        self::assertStringContainsString('Sichtbar &lt;b&gt;escaped&lt;/b&gt;', $response->body());
    }

    public function testCsrfRejectsMissingAndWrongTokenAndAcceptsValid(): void
    {
        $session = new Session(['driver' => 'array']);
        $csrf = new Csrf($session);
        $next = static fn (): Response => Response::text('ok');

        self::assertSame(403, $csrf->process(Request::create('POST', '/kontakt/'), $next)->status());
        $token = $csrf->token();
        self::assertSame(403, $csrf->process(Request::create('POST', '/kontakt/', ['_csrf' => str_repeat('a', 64)]), $next)->status());
        self::assertSame('ok', $csrf->process(Request::create('POST', '/kontakt/', ['_csrf' => $token]), $next)->body());
        self::assertSame('ok', $csrf->process(Request::create('POST', '/kontakt/', [], ['HTTP_X_CSRF_TOKEN' => $token]), $next)->body());
        self::assertSame('ok', $csrf->process(Request::create('GET', '/kontakt/'), $next)->body());
    }

    public function testSessionStartsOnlyWhenNeeded(): void
    {
        $kernel = $this->kernel();
        $kernel->handle(Request::create('GET', '/weg-verwaltung/'));
        self::assertFalse($kernel->container()->get(Session::class)->isStarted());

        $kernel = $this->kernel();
        $response = $kernel->handle(Request::create('GET', '/kontakt/'));
        self::assertTrue($kernel->container()->get(Session::class)->isStarted());
        self::assertSame('no-store, private', $response->header('Cache-Control'));
    }

    public function testMethodNotAllowedWithValidToken(): void
    {
        $kernel = $this->kernel();
        $token = $kernel->container()->get(Csrf::class)->token();
        $response = $kernel->handle(Request::create('POST', '/weg-verwaltung/', ['_csrf' => $token]));
        self::assertSame(405, $response->status());
        self::assertStringContainsString('GET', (string) $response->header('Allow'));
    }

    public function testLegacyRedirectRunsBeforeTrailingSlash(): void
    {
        $response = $this->get('/hausverwaltung');
        self::assertSame(301, $response->status());
        self::assertSame('/weg-verwaltung/', $response->header('Location'));
        self::assertNotNull($response->header('Content-Security-Policy'));
    }

    public function testGoneForWordpressEndpoints(): void
    {
        self::assertSame(410, $this->get('/wp-login.php')->status());
        self::assertSame(410, $this->get('/wp-admin/')->status());
    }

    public function testClientIpRespectsTrustedProxies(): void
    {
        $request = Request::create('GET', '/', [], ['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7, 172.18.0.5']);
        self::assertSame('203.0.113.7', $request->clientIp(['172.16.0.0/12']));
        self::assertSame('172.18.0.2', $request->clientIp([]));

        $spoofed = Request::create('GET', '/', [], ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7']);
        self::assertSame('198.51.100.9', $spoofed->clientIp(['172.16.0.0/12']));
    }

    public function testRequestContextNonceIsUrlSafe(): void
    {
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', (new RequestContext())->nonce());
    }

    public function testProductionStartIsAbortedWithoutValidAppKey(): void
    {
        foreach ([null, '', 'base64:***kein-base64***', 'base64:' . base64_encode('zu-kurz')] as $key) {
            Env::reset();
            Env::set('APP_ENV', 'production');
            Env::set('APP_KEY', $key);
            try {
                Kernel::fromGlobals(self::basePath());
                self::fail('Produktion ohne gültigen APP_KEY gestartet: ' . var_export($key, true));
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('APP_KEY', $e->getMessage());
            } finally {
                ini_set('display_errors', '1');
            }
        }
    }

    public function testProductionStartsWithValidAppKeyAndDevelopmentWithout(): void
    {
        Env::reset();
        Env::set('APP_ENV', 'production');
        Env::set('APP_KEY', 'base64:' . base64_encode(str_repeat('k', 32)));
        self::assertTrue(Kernel::fromGlobals(self::basePath())->isProduction());
        ini_set('display_errors', '1');

        Env::reset();
        Env::set('APP_ENV', 'development');
        Env::set('APP_KEY', null);
        self::assertFalse(Kernel::fromGlobals(self::basePath())->isProduction());
    }

    public function testStagingShowsGeneric500WithoutDetails(): void
    {
        $kernel = $this->kernel('staging');
        $kernel->container()->get(Router::class)->add('GET', '/kaputt/', [ThrowingController::class, 'show']);
        $response = $kernel->handle(Request::create('GET', '/kaputt/'));
        ini_set('display_errors', '1');

        self::assertSame(500, $response->status());
        self::assertStringContainsString('Vorübergehende Störung', $response->body());
        self::assertStringNotContainsString('Interner Fehler mit Details', $response->body());
        self::assertStringNotContainsString('ThrowingController', $response->body());
        self::assertStringNotContainsString('.php', $response->body());
    }
}
