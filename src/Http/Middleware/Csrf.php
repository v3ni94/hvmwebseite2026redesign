<?php

declare(strict_types=1);

namespace Hvm\Http\Middleware;

use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Http\Session as SessionStore;

/**
 * CSRF-Schutz: Token je Sitzung, Prüfung aller zustandsändernden Anfragen mit hash_equals.
 * Formularfeld "_csrf" (Twig: csrf_field()) oder Header "X-CSRF-Token".
 */
final class Csrf implements Middleware
{
    public const FIELD = '_csrf';
    private const SESSION_KEY = '_csrf_token';

    /**
     * @param list<string> $exempt Pfadpräfixe ohne Prüfung (nur für signierte Maschinenschnittstellen)
     */
    public function __construct(
        private readonly SessionStore $session,
        private readonly array $exempt = [],
    ) {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function isValid(?string $provided): bool
    {
        if ($provided === null || $provided === '') {
            return false;
        }
        $expected = $this->session->get(self::SESSION_KEY);

        return is_string($expected) && $expected !== '' && hash_equals($expected, $provided);
    }

    public function process(Request $request, callable $next): Response
    {
        if ($request->isSafe() || $this->isExempt($request->path())) {
            return $next($request);
        }

        $provided = $request->postValue(self::FIELD) ?? $request->header('X-CSRF-Token');
        if (!$this->isValid($provided)) {
            return Response::html(self::rejectedPage(), 403)->withHeader('Cache-Control', 'no-store');
        }

        return $next($request);
    }

    private function isExempt(string $path): bool
    {
        foreach ($this->exempt as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function rejectedPage(): string
    {
        return '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>Formular abgelaufen | Hausverwaltung Müller GmbH</title></head><body>'
            . '<main><h1>Formular abgelaufen</h1>'
            . '<p>Aus Sicherheitsgründen konnte das Formular nicht verarbeitet werden. '
            . 'Bitte laden Sie die Seite neu und senden Sie das Formular erneut.</p>'
            . '<p><a href="/">Zur Startseite</a></p></main></body></html>';
    }
}
