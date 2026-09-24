<?php

declare(strict_types=1);

namespace Hvm\Service;

use Hvm\Http\Request;
use Hvm\Support\Config;
use Hvm\Support\Container;
use Hvm\Support\Log;
use Throwable;

/**
 * Outbox-Verarbeitung ohne Worker-Dienst und ohne Cronjob (OUTBOX_MODE=inline, Webhosting per SFTP).
 *
 * Nach dem Senden der Antwort (Kernel::terminate, fastcgi_finish_request) werden bis zu LIMIT fällige Einträge
 * verarbeitet:
 * - nach jeder zustandsändernden Anfrage (POST, z. B. Angebotsformular), damit die Benachrichtigung sofort rausgeht;
 * - bei GET-Anfragen höchstens alle GET_INTERVAL Sekunden, damit Wiederholungen (Backoff) auch ohne neue
 *   Formulare nachgeholt werden.
 *
 * Sperre gegen Doppelverarbeitung: nicht blockierendes flock auf storage/cache/outbox-inline.lock (ein Lauf je
 * Server) und zusätzlich der Status-Claim in OutboxRepository::claim (auch über mehrere Server hinweg).
 * Fehler landen nur im Log, nie in der Antwort.
 */
final class InlineOutbox
{
    public const LIMIT = 5;
    public const GET_INTERVAL = 300;

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
        private readonly Log $log,
        private readonly string $stateDirectory,
    ) {
    }

    public function enabled(): bool
    {
        return $this->config->get('app.outbox_mode') === 'inline'
            && ((string) $this->config->get('app.db.name', '') !== '' || (string) $this->config->get('app.db.socket', '') !== '');
    }

    public function due(Request $request, ?int $now = null): bool
    {
        // Einrichtung: Tabellen fehlen womöglich noch, kein Versand anstoßen
        if (!$this->enabled() || str_starts_with($request->path(), '/_einrichtung/')) {
            return false;
        }
        if (!$request->isSafe()) {
            return true;
        }
        $now ??= time();
        $stamp = $this->stateDirectory . '/outbox-inline.stamp';
        $last = is_file($stamp) ? (int) @filemtime($stamp) : 0;

        return $now - $last >= self::GET_INTERVAL;
    }

    /**
     * @return array{claimed: int, sent: int, retried: int, failed: int, postponed: int}|null null, wenn ein anderer Lauf aktiv ist oder ein Fehler auftrat
     */
    public function run(): ?array
    {
        if (!is_dir($this->stateDirectory)) {
            @mkdir($this->stateDirectory, 0775, true);
        }
        $handle = @fopen($this->stateDirectory . '/outbox-inline.lock', 'c');
        if ($handle === false) {
            $this->log->warning('Outbox inline: Sperrdatei nicht beschreibbar');

            return null;
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return null;
            }
            @touch($this->stateDirectory . '/outbox-inline.stamp');
            try {
                /** @var OutboxWorker $worker */
                $worker = $this->container->get(OutboxWorker::class);
                $stats = $worker->runOnce(self::LIMIT);
                if ($stats['claimed'] > 0) {
                    $this->log->info('Outbox inline verarbeitet', $stats);
                }

                return $stats;
            } catch (Throwable $e) {
                $this->log->warning('Outbox inline fehlgeschlagen', ['fehler' => get_class($e)]);

                return null;
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
