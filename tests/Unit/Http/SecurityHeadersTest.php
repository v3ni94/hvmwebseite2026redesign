<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Http;

use Hvm\Http\Middleware\SecurityHeaders;
use Hvm\Http\Request;
use Hvm\Http\RequestContext;
use Hvm\Http\Response;
use Hvm\Tests\Unit\TestCase;

final class SecurityHeadersTest extends TestCase
{
    private function call(string $env, string $path = '/'): array
    {
        $context = new RequestContext();
        $response = (new SecurityHeaders($context, $env))
            ->process(Request::create('GET', $path), static fn (): Response => Response::html('ok'));

        return [$response, $context->nonce()];
    }

    public function testCspContainsNonceAndNoUnsafeInline(): void
    {
        [$response, $nonce] = $this->call('production');
        $csp = (string) $response->header('Content-Security-Policy');

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{20,}$/', $nonce);
        self::assertStringContainsString("script-src 'self' 'nonce-" . $nonce . "'", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
        self::assertSame(
            "default-src 'self'; script-src 'self' 'nonce-" . $nonce . "'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'; upgrade-insecure-requests",
            $csp
        );
    }

    public function testProductionHeaders(): void
    {
        [$response] = $this->call('production');
        self::assertSame('max-age=31536000; includeSubDomains', $response->header('Strict-Transport-Security'));
        self::assertNull($response->header('X-Robots-Tag'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->header('Referrer-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=(), interest-cohort=()', $response->header('Permissions-Policy'));
        self::assertSame('same-origin', $response->header('Cross-Origin-Opener-Policy'));
        self::assertSame('DENY', $response->header('X-Frame-Options'));
    }

    public function testNoindexOutsideProduction(): void
    {
        foreach (['development', 'staging'] as $env) {
            [$response] = $this->call($env);
            self::assertSame('noindex, nofollow', $response->header('X-Robots-Tag'), $env);
            self::assertNull($response->header('Strict-Transport-Security'), $env);
        }
    }

    public function testAdminIsAlwaysNoindex(): void
    {
        [$response] = $this->call('production', '/admin/');
        self::assertSame('noindex, nofollow', $response->header('X-Robots-Tag'));
    }

    public function testNonceChangesPerRequestAndMatchesHtml(): void
    {
        $kernel = $this->kernel('development');
        $first = $kernel->handle(Request::create('GET', '/'));
        $second = $kernel->handle(Request::create('GET', '/'));

        preg_match("/'nonce-([^']+)'/", (string) $first->header('Content-Security-Policy'), $a);
        preg_match("/'nonce-([^']+)'/", (string) $second->header('Content-Security-Policy'), $b);
        self::assertNotSame($a[1], $b[1]);
        self::assertStringContainsString('nonce="' . $a[1] . '"', $first->body());
        self::assertStringNotContainsString('style="', $first->body());
    }
}
