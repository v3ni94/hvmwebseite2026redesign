<?php

declare(strict_types=1);

namespace Hvm\Support;

/**
 * Lädt config/*.php (jede Datei gibt ein Array zurück) und bietet Zugriff per Punktnotation,
 * z. B. $config->get('unternehmen.anschrift.ort').
 */
final class Config
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(private array $items = [])
    {
    }

    public static function fromDirectory(string $directory): self
    {
        $items = [];
        $files = glob(rtrim($directory, '/') . '/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $data = (static fn (string $path): mixed => require $path)($file);
            if (!is_array($data)) {
                throw new \UnexpectedValueException(sprintf('Konfigurationsdatei %s gibt kein Array zurück.', basename($file)));
            }
            $items[basename($file, '.php')] = $data;
        }

        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function has(string $key): bool
    {
        $marker = new \stdClass();

        return $this->get($key, $marker) !== $marker;
    }

    /**
     * @return array<string, mixed>
     */
    public function array(string $key): array
    {
        $value = $this->get($key, []);

        return is_array($value) ? $value : [];
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref = &$this->items;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }
}
