<?php

declare(strict_types=1);

namespace Hvm\Http;

/**
 * Unveränderliche Abbildung einer HTTP-Anfrage.
 */
final class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $post
     * @param array<string, mixed>  $server
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $files
     * @param array<string, mixed>  $attributes
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $server = [],
        private readonly array $cookies = [],
        private readonly array $files = [],
        private array $attributes = [],
        private readonly string $body = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        /** @var array<string, string> $cookies */
        $cookies = array_filter($_COOKIE, 'is_string');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::normalizePath($uri),
            $_GET,
            $_POST,
            $_SERVER,
            $cookies,
            $_FILES,
            [],
            ''
        );
    }

    /**
     * Erzeugt eine Anfrage für Tests und interne Aufrufe.
     *
     * @param array<string, mixed>  $post
     * @param array<string, mixed>  $server
     * @param array<string, string> $cookies
     */
    public static function create(string $method, string $uri, array $post = [], array $server = [], array $cookies = []): self
    {
        $query = [];
        $queryString = str_contains($uri, '?') ? explode('#', explode('?', $uri, 2)[1], 2)[0] : '';
        if ($queryString !== '') {
            parse_str($queryString, $query);
        }
        $server = array_merge([
            'REQUEST_METHOD' => strtoupper($method),
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => $queryString,
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'localhost',
        ], $server);

        /** @var array<string, mixed> $query */
        return new self(strtoupper($method), self::normalizePath($uri), $query, $post, $server, $cookies);
    }

    private static function normalizePath(string $uri): string
    {
        // Absolute Form (http://host/pfad) über parse_url, sonst Teil vor "?" und "#".
        // parse_url würde "//host/pfad" als Authority deuten.
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $uri)) {
            $path = parse_url($uri, PHP_URL_PATH);
        } else {
            $path = explode('#', explode('?', $uri, 2)[0], 2)[0];
        }
        if (!is_string($path) || $path === '') {
            return '/';
        }
        $path = rawurldecode($path);
        if (str_contains($path, "\0")) {
            return '/';
        }

        return str_starts_with($path, '/') ? $path : '/' . $path;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * GET und HEAD verändern keinen Zustand.
     */
    public function isSafe(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function queryString(): string
    {
        return (string) ($this->server['QUERY_STRING'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    public function queryValue(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function post(): array
    {
        return $this->post;
    }

    public function postValue(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->files;
    }

    public function body(): string
    {
        return $this->body !== '' ? $this->body : (string) file_get_contents('php://input');
    }

    /**
     * @return array<string, string>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function server(string $key, ?string $default = null): ?string
    {
        $value = $this->server[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (in_array($key, ['HTTP_CONTENT_TYPE', 'HTTP_CONTENT_LENGTH'], true)) {
            $key = substr($key, 5);
        }

        return $this->server($key);
    }

    public function isSecure(): bool
    {
        $https = strtolower((string) $this->server('HTTPS', ''));

        return ($https !== '' && $https !== 'off') || $this->server('REQUEST_SCHEME') === 'https';
    }

    /**
     * Client-IP: REMOTE_ADDR, außer die Anfrage kommt von einem vertrauenswürdigen Proxy.
     * Dann gilt der erste nicht vertrauenswürdige Eintrag aus X-Forwarded-For (von rechts gelesen).
     *
     * @param list<string> $trustedProxies CIDR-Liste
     */
    public function clientIp(array $trustedProxies = []): string
    {
        $remote = (string) $this->server('REMOTE_ADDR', '0.0.0.0');
        if ($trustedProxies === [] || !IpRange::matchesAny($remote, $trustedProxies)) {
            return $remote;
        }
        $forwarded = (string) $this->header('X-Forwarded-For');
        if ($forwarded === '') {
            return $remote;
        }
        $chain = array_reverse(array_map('trim', explode(',', $forwarded)));
        foreach ($chain as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!IpRange::matchesAny($ip, $trustedProxies)) {
                return $ip;
            }
        }

        return $remote;
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;

        return $clone;
    }
}
