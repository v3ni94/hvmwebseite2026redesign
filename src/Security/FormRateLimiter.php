<?php

declare(strict_types=1);

namespace Hvm\Security;

use Hvm\Http\IpRange;
use Hvm\Support\Clock;
use Hvm\Support\Config;
use Hvm\Support\Container;

/**
 * Rate Limiting der Formulare unabhängig vom Speichermodus.
 *
 * STORAGE_MODE=datei: FileRateLimiter in storage/ratelimit (Dateiname als HMAC, keine IP im Klartext),
 * STORAGE_MODE=datenbank: RateLimiter (Tabelle rate_limits). Der Datenbankdienst wird erst bei Bedarf geholt,
 * damit im Dateimodus nie eine Verbindung aufgebaut wird.
 */
final class FormRateLimiter
{
    private ?FileRateLimiter $file = null;

    public function __construct(
        private readonly Config $config,
        private readonly Container $container,
    ) {
    }

    public function fileMode(): bool
    {
        return $this->config->get('app.storage_mode', 'datei') === 'datei';
    }

    /**
     * @return array{allowed: bool, hits: int, limit: int, retry_after: int}
     */
    public function hit(string $bucket, string $ip, int $limit, int $windowSeconds): array
    {
        if (!$this->fileMode()) {
            /** @var RateLimiter $db */
            $db = $this->container->get(RateLimiter::class);

            return $db->hit($bucket, $ip, $limit, $windowSeconds);
        }
        $now = Clock::now()->getTimestamp();
        $window = $this->file()->hitWindow($bucket, IpRange::clientKey($ip), $windowSeconds, $now);

        return [
            'allowed' => $window['hits'] <= $limit,
            'hits' => $window['hits'],
            'limit' => $limit,
            'retry_after' => max(1, $window['start'] + $windowSeconds - $now),
        ];
    }

    private function file(): FileRateLimiter
    {
        $dir = $this->config->get('app.ratelimit_path');
        $dir = is_string($dir) && $dir !== '' ? $dir : rtrim((string) $this->config->get('app.base_path', ''), '/') . '/storage/ratelimit';

        return $this->file ??= new FileRateLimiter(
            $dir,
            SpamGuard::deriveKey($this->config, 'ratelimit')
        );
    }
}
