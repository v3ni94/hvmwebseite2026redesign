<?php

declare(strict_types=1);

namespace Hvm\Http;

/**
 * Router für statische Pfade und einfache Platzhalter ({slug}, Muster [a-z0-9-]+).
 *
 * Routenformat (config/routes.php):
 *   ['GET', '/weg-verwaltung/', [PageController::class, 'show'], ['page' => 'weg-verwaltung'], ['nicht_in' => ['production']]]
 */
final class Router
{
    public const PLACEHOLDER_PATTERN = '[a-z0-9-]+';

    /** @var array<string, array<string, array{handler: array{0: class-string, 1: string}, defaults: array<string, mixed>}>> Pfad => Methode => Route */
    private array $static = [];

    /** @var list<array{method: string, path: string, regex: string, handler: array{0: class-string, 1: string}, defaults: array<string, mixed>}> */
    private array $dynamic = [];

    /**
     * @param iterable<array{0: string, 1: string, 2: array{0: class-string, 1: string}, 3?: array<string, mixed>, 4?: array<string, mixed>}> $routes
     */
    public static function fromArray(iterable $routes, string $env = 'production'): self
    {
        $router = new self();
        foreach ($routes as $route) {
            $options = $route[4] ?? [];
            $excluded = (array) ($options['nicht_in'] ?? []);
            if (in_array($env, $excluded, true)) {
                continue;
            }
            $router->add($route[0], $route[1], $route[2], $route[3] ?? []);
        }

        return $router;
    }

    /**
     * @param string|list<string>                $methods
     * @param array{0: class-string, 1: string}  $handler
     * @param array<string, mixed>               $defaults
     */
    public function add(string|array $methods, string $path, array $handler, array $defaults = []): void
    {
        foreach ((array) $methods as $method) {
            $method = strtoupper($method);
            if (!str_contains($path, '{')) {
                $this->static[$path][$method] = ['handler' => $handler, 'defaults' => $defaults];
                continue;
            }
            $this->dynamic[] = [
                'method' => $method,
                'path' => $path,
                'regex' => $this->compile($path),
                'handler' => $handler,
                'defaults' => $defaults,
            ];
        }
    }

    private function compile(string $path): string
    {
        $parts = preg_split('/(\{[a-z_][a-z0-9_]*\})/i', $path, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{([a-z_][a-z0-9_]*)\}$/i', $part, $m)) {
                $regex .= '(?P<' . $m[1] . '>' . self::PLACEHOLDER_PATTERN . ')';
                continue;
            }
            $regex .= preg_quote($part, '#');
        }

        return '#^' . $regex . '$#';
    }

    public function match(string $method, string $path): RouteMatch
    {
        $method = strtoupper($method);
        $lookup = $method === 'HEAD' ? ['HEAD', 'GET'] : [$method];
        $allowed = [];

        if (isset($this->static[$path])) {
            foreach ($lookup as $candidate) {
                if (isset($this->static[$path][$candidate])) {
                    $route = $this->static[$path][$candidate];

                    return RouteMatch::found($route['handler'], $route['defaults']);
                }
            }
            $allowed = array_keys($this->static[$path]);
        }

        foreach ($this->dynamic as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if (!in_array($route['method'], $lookup, true)) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

            return RouteMatch::found($route['handler'], array_merge($route['defaults'], $params));
        }

        if ($allowed !== []) {
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }

            return RouteMatch::methodNotAllowed(array_values(array_unique($allowed)));
        }

        return RouteMatch::notFound();
    }

    /**
     * Alle statischen GET-Routen (für die Sitemap).
     *
     * @return list<array{path: string, handler: array{0: class-string, 1: string}, defaults: array<string, mixed>}>
     */
    public function staticGetRoutes(): array
    {
        $result = [];
        foreach ($this->static as $path => $methods) {
            if (isset($methods['GET'])) {
                $result[] = ['path' => $path, 'handler' => $methods['GET']['handler'], 'defaults' => $methods['GET']['defaults']];
            }
        }

        return $result;
    }
}
