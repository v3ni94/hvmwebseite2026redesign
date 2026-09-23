<?php

declare(strict_types=1);

namespace Hvm\Http\Middleware;

use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Http\Session as SessionStore;

/**
 * Startet die Sitzung nur bei Bedarf: bei POST, auf Formular- und Admin-Pfaden
 * oder wenn bereits ein Sitzungs-Cookie vorliegt. Sonst bleibt die Seite cookiefrei und cachebar.
 * Templates können die Sitzung zusätzlich über csrf_field() starten.
 */
final class Session implements Middleware
{
    /**
     * @param list<string> $paths Pfadpräfixe, auf denen die Sitzung immer startet
     */
    public function __construct(
        private readonly SessionStore $session,
        private readonly array $paths = [],
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->needsSession($request)) {
            $this->session->start();
        }

        $response = $next($request);

        if ($this->session->isStarted()) {
            $response = $response->withHeader('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    private function needsSession(Request $request): bool
    {
        if (!$request->isSafe() || $request->cookie($this->session->name()) !== null) {
            return true;
        }
        foreach ($this->paths as $prefix) {
            if (str_starts_with($request->path(), $prefix)) {
                return true;
            }
        }

        return false;
    }
}
