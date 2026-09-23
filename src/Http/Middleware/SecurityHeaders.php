<?php

declare(strict_types=1);

namespace Hvm\Http\Middleware;

use Hvm\Http\Request;
use Hvm\Http\RequestContext;
use Hvm\Http\Response;

/**
 * Sicherheitsheader laut docs/architektur.md Abschnitt 4, CSP mit Nonce je Anfrage.
 */
final class SecurityHeaders implements Middleware
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly string $env,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        return $this->apply($next($request), $request);
    }

    public function apply(Response $response, ?Request $request = null): Response
    {
        $response = $response
            ->withHeader('Content-Security-Policy', self::csp($this->context->nonce()))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), interest-cohort=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('X-Frame-Options', 'DENY');

        if ($this->env === 'production') {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $isAdmin = $request !== null && str_starts_with($request->path(), '/admin/');
        if ($this->env !== 'production' || $isAdmin) {
            $response = $response->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    public static function csp(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-" . $nonce . "'",
            "style-src 'self'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
            'upgrade-insecure-requests',
        ]);
    }
}
