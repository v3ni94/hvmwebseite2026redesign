<?php

declare(strict_types=1);

namespace Hvm\Http;

/**
 * Sitzung, die erst bei Bedarf startet.
 *
 * Treiber "native" nutzt die PHP-Sitzung mit gehärteten Cookie-Parametern,
 * Treiber "array" hält die Daten nur im Speicher (Tests, CLI).
 */
final class Session
{
    private const LAST_ACTIVITY = '_hvm_last_activity';

    private bool $started = false;

    /** @var array<string, mixed> */
    private array $memory = [];

    /**
     * @param array{name?: string, secure?: bool, idle_timeout?: int, driver?: string} $options
     */
    public function __construct(private readonly array $options = [])
    {
    }

    public function name(): string
    {
        return $this->options['name'] ?? 'hvm_sid';
    }

    private function isNative(): bool
    {
        return ($this->options['driver'] ?? 'native') === 'native';
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        if ($this->isNative()) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                if (headers_sent()) {
                    throw new \RuntimeException('Sitzung kann nach begonnener Ausgabe nicht gestartet werden.');
                }
                $idle = (int) ($this->options['idle_timeout'] ?? 1800);
                session_name($this->name());
                session_start([
                    'use_strict_mode' => true,
                    'use_only_cookies' => true,
                    'use_trans_sid' => false,
                    // Cache-Header setzt die Middleware, nicht PHP
                    'cache_limiter' => '',
                    'cookie_httponly' => true,
                    'cookie_secure' => (bool) ($this->options['secure'] ?? true),
                    'cookie_samesite' => 'Lax',
                    'cookie_path' => '/',
                    'cookie_lifetime' => 0,
                    'gc_maxlifetime' => max($idle, 300),
                ]);
            }
        }
        $this->started = true;
        $this->enforceIdleTimeout();
    }

    private function enforceIdleTimeout(): void
    {
        $idle = (int) ($this->options['idle_timeout'] ?? 1800);
        $now = time();
        $last = $this->get(self::LAST_ACTIVITY);
        if (is_int($last) && $idle > 0 && ($now - $last) > $idle) {
            $this->clear();
            $this->regenerate();
        }
        $this->set(self::LAST_ACTIVITY, $now);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();
        if ($this->isNative()) {
            return $_SESSION[$key] ?? $default;
        }

        return $this->memory[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        if ($this->isNative()) {
            $_SESSION[$key] = $value;

            return;
        }
        $this->memory[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        if ($this->isNative()) {
            unset($_SESSION[$key]);

            return;
        }
        unset($this->memory[$key]);
    }

    public function clear(): void
    {
        if ($this->isNative()) {
            $_SESSION = [];

            return;
        }
        $this->memory = [];
    }

    /**
     * Neue Sitzungs-ID, z. B. nach Anmeldung (Schutz vor Session Fixation).
     */
    public function regenerate(): void
    {
        $this->start();
        if ($this->isNative() && session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        $this->clear();
        if ($this->isNative() && session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->started = false;
    }
}
