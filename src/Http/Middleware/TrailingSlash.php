<?php

declare(strict_types=1);

namespace Hvm\Http\Middleware;

use Hvm\Http\Request;
use Hvm\Http\Response;

/**
 * HTML-Seiten enden auf "/". GET- und HEAD-Anfragen ohne Schrägstrich werden per 301 umgeleitet.
 * Ausgenommen sind Pfade, deren letztes Segment eine Dateiendung hat (z. B. /sitemap.xml).
 */
final class TrailingSlash implements Middleware
{
    public function process(Request $request, callable $next): Response
    {
        $path = $request->path();
        if (!in_array($request->method(), ['GET', 'HEAD'], true) || str_ends_with($path, '/')) {
            return $next($request);
        }

        $last = (string) substr($path, (int) strrpos($path, '/') + 1);
        if (str_contains($last, '.')) {
            return $next($request);
        }

        // Doppelte Schrägstriche am Anfang würden als protokollrelative URL gedeutet
        $target = '/' . ltrim($path, '/') . '/';
        $query = $request->queryString();
        if ($query !== '') {
            $target .= '?' . $query;
        }

        return Response::redirect($target, 301)->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
