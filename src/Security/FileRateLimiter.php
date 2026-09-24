<?php

declare(strict_types=1);

namespace Hvm\Security;

/**
 * Rate Limiting ohne Datenbank (Web-Einrichtung, bevor Migrationen gelaufen sind).
 *
 * Je Bucket und Schlüssel eine kleine JSON-Datei in storage/ratelimit mit festem Zeitfenster.
 * Der Dateiname ist ein HMAC des Schlüssels (keine IP-Adressen im Klartext). Zugriff mit flock.
 */
final class FileRateLimiter
{
    public function __construct(
        private readonly string $directory,
        private readonly string $key,
    ) {
    }

    private function path(string $bucket, string $identifier): string
    {
        $bucket = (string) preg_replace('/[^a-z0-9_-]/', '', strtolower($bucket));

        return rtrim($this->directory, '/') . '/' . $bucket . '-' . substr(hash_hmac('sha256', $bucket . '|' . $identifier, $this->key), 0, 40) . '.json';
    }

    /**
     * Anzahl der Zugriffe im laufenden Fenster, ohne zu zählen.
     */
    public function hits(string $bucket, string $identifier, int $windowSeconds, ?int $now = null): int
    {
        $now ??= time();
        $file = $this->path($bucket, $identifier);
        if (!is_file($file)) {
            return 0;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || (int) ($data['start'] ?? 0) + $windowSeconds <= $now) {
            return 0;
        }

        return (int) ($data['hits'] ?? 0);
    }

    public function tooMany(string $bucket, string $identifier, int $limit, int $windowSeconds, ?int $now = null): bool
    {
        return $this->hits($bucket, $identifier, $windowSeconds, $now) >= $limit;
    }

    /**
     * Zählt einen Zugriff. Liefert die Anzahl im laufenden Fenster.
     */
    public function hit(string $bucket, string $identifier, int $windowSeconds, ?int $now = null): int
    {
        $now ??= time();
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('storage/ratelimit ist nicht beschreibbar.');
        }
        $handle = @fopen($this->path($bucket, $identifier), 'c+');
        if ($handle === false) {
            throw new \RuntimeException('storage/ratelimit ist nicht beschreibbar.');
        }
        try {
            flock($handle, LOCK_EX);
            $data = json_decode((string) stream_get_contents($handle), true);
            if (!is_array($data) || (int) ($data['start'] ?? 0) + $windowSeconds <= $now) {
                $data = ['start' => $now, 'hits' => 0];
            }
            $data['hits'] = (int) $data['hits'] + 1;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($data));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return (int) $data['hits'];
    }

    public function reset(string $bucket, string $identifier): void
    {
        @unlink($this->path($bucket, $identifier));
    }
}
