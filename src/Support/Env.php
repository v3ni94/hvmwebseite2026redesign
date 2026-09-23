<?php

declare(strict_types=1);

namespace Hvm\Support;

/**
 * Eigener, abhängigkeitsfreier Parser für .env-Dateien.
 *
 * Reihenfolge der Auflösung: gesetzte Überschreibungen (Tests), echte
 * Umgebungsvariablen des Prozesses (Docker, FPM), Werte aus der .env-Datei.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $file = [];

    /** @var array<string, string|null> */
    private static array $overrides = [];

    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        $content = (string) file_get_contents($path);
        self::$file = array_merge(self::$file, self::parse($content));
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $content): array
    {
        $result = [];
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (str_starts_with($content, "\u{FEFF}")) {
            $content = substr($content, 3);
        }

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            $result[$key] = self::parseValue(ltrim(substr($line, $pos + 1)), $result);
        }

        return $result;
    }

    /**
     * @param array<string, string> $known bereits gelesene Werte für ${VAR}-Verweise
     */
    private static function parseValue(string $raw, array $known): string
    {
        if ($raw === '') {
            return '';
        }

        $quote = $raw[0];
        if ($quote === '"' || $quote === "'") {
            $end = self::findClosingQuote($raw, $quote);
            $value = $end === null ? substr($raw, 1) : substr($raw, 1, $end - 1);
            if ($quote === '"') {
                $value = strtr($value, ['\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\"' => '"', '\\\\' => '\\']);
                $value = self::interpolate($value, $known);
            }

            return $value;
        }

        // Unquotierter Wert: Kommentar nach " #" abschneiden
        $hash = strpos($raw, ' #');
        if ($hash !== false) {
            $raw = substr($raw, 0, $hash);
        }

        return self::interpolate(trim($raw), $known);
    }

    private static function findClosingQuote(string $raw, string $quote): ?int
    {
        $length = strlen($raw);
        for ($i = 1; $i < $length; $i++) {
            if ($raw[$i] === '\\' && $quote === '"') {
                $i++;
                continue;
            }
            if ($raw[$i] === $quote) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $known
     */
    private static function interpolate(string $value, array $known): string
    {
        return (string) preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static fn (array $m): string => $known[$m[1]] ?? (self::get($m[1]) ?? ''),
            $value
        );
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$overrides)) {
            return self::$overrides[$key] ?? $default;
        }
        $real = getenv($key);
        if ($real !== false) {
            return $real;
        }
        if (isset($_ENV[$key]) && is_scalar($_ENV[$key])) {
            return (string) $_ENV[$key];
        }
        if (array_key_exists($key, self::$file)) {
            return self::$file[$key];
        }

        return $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key);

        return $value === null || $value === '' ? $default : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null || trim($value) === '') {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 'ja'], true);
    }

    public static function int(string $key, ?int $default = null): ?int
    {
        $value = self::get($key);
        if ($value === null || trim($value) === '' || !is_numeric(trim($value))) {
            return $default;
        }

        return (int) trim($value);
    }

    /**
     * Kommagetrennte Liste, leere Einträge entfernt.
     *
     * @return list<string>
     */
    public static function list(string $key): array
    {
        $value = self::get($key);
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
    }

    /**
     * Überschreibt einen Wert zur Laufzeit (vor allem für Tests). null entfernt die Variable.
     */
    public static function set(string $key, ?string $value): void
    {
        self::$overrides[$key] = $value;
    }

    public static function reset(): void
    {
        self::$file = [];
        self::$overrides = [];
    }
}
