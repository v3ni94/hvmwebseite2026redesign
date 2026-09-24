<?php

declare(strict_types=1);

namespace Hvm\Http\Middleware;

use Hvm\Http\Request;
use Hvm\Http\Response;

/**
 * HTTP Basic Auth für Staging ohne Server-Konfiguration (Webhosting ohne Traefik bzw. htpasswd).
 *
 * Aktiv nur bei APP_ENV=staging und gesetztem STAGING_BASIC_AUTH im Format benutzer:password_hash
 * (bcrypt oder Argon2, z. B. aus der Hash-Hilfe der Einrichtungsseite). /health bleibt frei.
 *
 * - apr1- und SHA-Hashes (htpasswd für Traefik, docs/betrieb.md 1.2) prüft der vorgeschaltete Proxy;
 *   die Anwendung ignoriert sie, damit Docker-Staging nicht doppelt und unerfüllbar fragt.
 * - Jeder andere nicht erkennbare Wert (z. B. ein Klartextpasswort) sperrt: sicher statt offen.
 * - Bei PHP-FPM bzw. CGI reicht Apache den Authorization-Header nur weiter, wenn public/.htaccess ihn per
 *   RewriteRule mit E=HTTP_AUTHORIZATION setzt; ausgewertet werden daher PHP_AUTH_USER, HTTP_AUTHORIZATION
 *   und REDIRECT_HTTP_AUTHORIZATION.
 */
final class StagingBasicAuth implements Middleware
{
    public const REALM = 'Staging Hausverwaltung Mueller';

    /** @var list<string> */
    private const FREI = ['/health'];

    public function __construct(
        private readonly string $env,
        private readonly ?string $credentials,
    ) {
    }

    public function isActive(): bool
    {
        if ($this->env !== 'staging' || $this->credentials === null || trim($this->credentials) === '') {
            return false;
        }
        $hash = self::parse($this->credentials)[1];

        return !self::isUpstreamFormat($hash);
    }

    public function process(Request $request, callable $next): Response
    {
        if (!$this->isActive() || in_array($request->path(), self::FREI, true)) {
            return $next($request);
        }
        [$user, $password] = self::provided($request);
        if ($user !== null && $this->verify($user, (string) $password)) {
            return $next($request);
        }

        return Response::text("Anmeldung erforderlich.\n", 401)
            ->withHeader('WWW-Authenticate', 'Basic realm="' . self::REALM . '", charset="UTF-8"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function verify(string $user, string $password): bool
    {
        [$expectedUser, $hash] = self::parse((string) $this->credentials);
        $userOk = hash_equals($expectedUser, $user);
        // password_verify immer ausführen (gleiche Laufzeit bei falschem Benutzernamen)
        $known = $hash !== '' && (password_get_info($hash)['algo'] ?? null) !== null;
        $passwordOk = password_verify($password, $known ? $hash : self::DUMMY_HASH);

        return $userOk && $known && $passwordOk && $expectedUser !== '';
    }

    /** bcrypt-Hash eines Zufallswerts, nur für gleichmäßige Laufzeit */
    private const DUMMY_HASH = '$2y$12$GoTdZd/7L8HPN3xT4RqdB.JRSQ2L1hTHtqomeRJJzMHfSVTA5C9.K';

    /**
     * @return array{0: string, 1: string} Benutzer und Hash
     */
    private static function parse(string $credentials): array
    {
        $credentials = trim($credentials);
        if (!str_contains($credentials, ':')) {
            return ['', ''];
        }
        [$user, $hash] = explode(':', $credentials, 2);

        return [trim($user), trim($hash)];
    }

    private static function isUpstreamFormat(string $hash): bool
    {
        return str_starts_with($hash, '$apr1$') || str_starts_with($hash, '{SHA}');
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    public static function provided(Request $request): array
    {
        $user = $request->server('PHP_AUTH_USER');
        if (is_string($user) && $user !== '') {
            $password = $request->server('PHP_AUTH_PW');

            return [$user, is_string($password) ? $password : ''];
        }
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $header = $request->server($key);
            if (!is_string($header) || stripos($header, 'basic ') !== 0) {
                continue;
            }
            $decoded = base64_decode(trim(substr($header, 6)), true);
            if ($decoded === false || !str_contains($decoded, ':')) {
                return [null, null];
            }
            [$u, $p] = explode(':', $decoded, 2);

            return [$u, $p];
        }

        return [null, null];
    }
}
