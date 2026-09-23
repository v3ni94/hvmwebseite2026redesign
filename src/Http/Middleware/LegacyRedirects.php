<?php

declare(strict_types=1);

namespace Hvm\Http\Middleware;

use Hvm\Http\Request;
use Hvm\Http\Response;

/**
 * Leitet Adressen der Altseite um (config/redirects.php).
 *
 * Eintrag: ['von' => '/hausverwaltung/', 'nach' => '/weg-verwaltung/', 'status' => 301, 'typ' => 'exakt'|'praefix'].
 * Vergleich ohne Beachtung von Groß- und Kleinschreibung und mit oder ohne abschließenden Schrägstrich.
 * Exakte Treffer haben Vorrang vor Präfixen, längere Präfixe vor kürzeren. Status 410 liefert "Gone".
 */
final class LegacyRedirects implements Middleware
{
    /** @var array<string, array{nach: ?string, status: int}> */
    private array $exact = [];

    /** @var list<array{von: string, nach: ?string, status: int}> */
    private array $prefix = [];

    /**
     * @param list<array{von: string, nach?: ?string, status?: int, typ?: string}> $rules
     */
    public function __construct(array $rules)
    {
        foreach ($rules as $rule) {
            $status = (int) ($rule['status'] ?? 301);
            if (!in_array($status, [301, 302, 307, 308, 410], true)) {
                throw new \InvalidArgumentException(sprintf('Ungültiger Status %d für %s.', $status, $rule['von']));
            }
            $entry = ['nach' => $rule['nach'] ?? null, 'status' => $status];
            if (($rule['typ'] ?? 'exakt') === 'praefix') {
                $this->prefix[] = ['von' => self::normalize($rule['von'])] + $entry;
                continue;
            }
            $this->exact[self::normalize($rule['von'])] = $entry;
        }
        usort($this->prefix, static fn (array $a, array $b): int => strlen($b['von']) <=> strlen($a['von']));
    }

    private static function normalize(string $path): string
    {
        $path = strtolower($path);

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function process(Request $request, callable $next): Response
    {
        $rule = $this->find($request->path());
        if ($rule === null) {
            return $next($request);
        }

        if ($rule['status'] === 410) {
            return Response::html(self::gonePage(), 410)->withHeader('Cache-Control', 'public, max-age=86400');
        }

        $target = (string) $rule['nach'];
        $query = $request->queryString();
        if ($query !== '' && !str_contains($target, '?')) {
            $target .= '?' . $query;
        }

        $response = Response::redirect($target, $rule['status']);

        return $rule['status'] === 301
            ? $response->withHeader('Cache-Control', 'public, max-age=86400')
            : $response->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @return array{nach: ?string, status: int}|null
     */
    public function find(string $path): ?array
    {
        $normalized = self::normalize($path);
        if (isset($this->exact[$normalized])) {
            return $this->exact[$normalized];
        }
        foreach ($this->prefix as $rule) {
            if ($normalized === $rule['von'] || str_starts_with($normalized, $rule['von'] . '/')) {
                return ['nach' => $rule['nach'], 'status' => $rule['status']];
            }
        }

        return null;
    }

    private static function gonePage(): string
    {
        return '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>Seite nicht mehr vorhanden | Hausverwaltung Müller GmbH</title></head><body>'
            . '<main><h1>Diese Seite gibt es nicht mehr</h1>'
            . '<p>Die angeforderte Adresse wurde dauerhaft entfernt.</p>'
            . '<p><a href="/">Zur Startseite</a></p></main></body></html>';
    }
}
