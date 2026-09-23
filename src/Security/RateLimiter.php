<?php

declare(strict_types=1);

namespace Hvm\Security;

use DateTimeImmutable;
use Hvm\Support\Clock;
use Hvm\Support\Config;
use PDO;

/**
 * Rate Limiting mit festen Zeitfenstern in der Tabelle rate_limits.
 *
 * Der Schlüssel (z. B. die Client-IP) wird nie im Klartext gespeichert, sondern als HMAC-SHA256
 * mit einem aus APP_KEY abgeleiteten Schlüssel. Abgelaufene Fenster werden gelegentlich gelöscht.
 */
final class RateLimiter
{
    private readonly string $key;

    public function __construct(private readonly PDO $pdo, Config $config)
    {
        $this->key = SpamGuard::deriveKey($config, 'ratelimit');
    }

    public function hash(string $identifier): string
    {
        return hash_hmac('sha256', $identifier, $this->key);
    }

    /**
     * Zählt einen Zugriff und prüft das Limit.
     *
     * @return array{allowed: bool, hits: int, limit: int, retry_after: int} retry_after in Sekunden bis Fensterende
     */
    public function hit(string $bucket, string $identifier, int $limit, int $windowSeconds): array
    {
        $now = Clock::now();
        $start = $this->windowStart($now, $windowSeconds);
        $keyHash = $this->hash($identifier);

        $stmt = $this->pdo->prepare(
            'INSERT INTO rate_limits (bucket, key_hash, window_start, hits) VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1'
        );
        $stmt->execute([$bucket, $keyHash, $start->format('Y-m-d H:i:s')]);

        $hits = $this->hits($bucket, $identifier, $windowSeconds);
        $this->maybeCleanup($now);

        return [
            'allowed' => $hits <= $limit,
            'hits' => $hits,
            'limit' => $limit,
            'retry_after' => max(1, $start->getTimestamp() + $windowSeconds - $now->getTimestamp()),
        ];
    }

    /**
     * Prüft, ohne zu zählen.
     */
    public function tooMany(string $bucket, string $identifier, int $limit, int $windowSeconds): bool
    {
        return $this->hits($bucket, $identifier, $windowSeconds) >= $limit;
    }

    public function hits(string $bucket, string $identifier, int $windowSeconds): int
    {
        $start = $this->windowStart(Clock::now(), $windowSeconds);
        $stmt = $this->pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = ? AND key_hash = ? AND window_start = ?');
        $stmt->execute([$bucket, $this->hash($identifier), $start->format('Y-m-d H:i:s')]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function windowStart(DateTimeImmutable $now, int $windowSeconds): DateTimeImmutable
    {
        $windowSeconds = max(1, $windowSeconds);
        $ts = intdiv($now->getTimestamp(), $windowSeconds) * $windowSeconds;

        return $now->setTimestamp($ts);
    }

    private function maybeCleanup(DateTimeImmutable $now): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }
        $stmt = $this->pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?');
        $stmt->execute([$now->modify('-1 day')->format('Y-m-d H:i:s')]);
    }
}
