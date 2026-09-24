<?php

declare(strict_types=1);

namespace Hvm\Service;

use Hvm\Security\Crypto;
use Hvm\Support\Clock;
use Hvm\Support\Log;
use Throwable;

/**
 * Outbox ohne Datenbank (STORAGE_MODE=datei, docs/n8n-webhook.md).
 *
 * Jeder Auftrag (Webhook an n8n oder Mail) ist eine eigene Datei storage/outbox/<id>.job:
 * - Inhalt JSON, verschlüsselt mit libsodium secretbox (Schlüssel aus APP_KEY, Zweck "outbox-datei");
 * - Dateirechte 0600, atomar geschrieben (temporäre Datei im selben Ordner, danach rename);
 * - Sperre je Datei mit flock (nicht blockierend), damit Inline-Verarbeitung und bin/worker.php nie doppelt senden.
 *
 * Versand wie OutboxWorker: Wiederholung mit Backoff 1, 5, 15, 60, 240 Minuten, fehlende Konfiguration stellt
 * zurück, ohne als Fehlversuch zu zählen. Nach erfolgreichem Versand wird die Datei gelöscht (bei Mails mit
 * Bewerbungsanhang zusätzlich die verschlüsselte PDF-Datei). Nach dem letzten Fehlversuch wird der Auftrag nach
 * storage/outbox/fehlgeschlagen/ verschoben, das Log enthält nur Kennungen und bereinigte Fehlermeldungen.
 * cleanup() löscht fehlgeschlagene Aufträge nach LEAD_RETENTION_DAYS, spätestens nach 30 Tagen.
 */
final class FileOutbox
{
    public const FAILED_DIR = 'fehlgeschlagen';
    public const MAX_RETENTION_DAYS = 30;
    public const SUFFIX = '.job';
    public const UPLOAD_SUBDIR = 'storage/uploads/bewerbungen';

    public function __construct(
        private readonly string $directory,
        private readonly string $key,
        private readonly MailService $mail,
        private readonly WebhookService $webhook,
        private readonly Log $log,
        private readonly string $basePath,
        // Schlüssel der Bewerbungsdateien (Zweck "bewerbung-upload", wie ApplicationService)
        private readonly string $uploadKey,
        private readonly ?int $retentionDays = null,
    ) {
    }

    public function directory(): string
    {
        return rtrim($this->directory, '/');
    }

    public function failedDirectory(): string
    {
        return $this->directory() . '/' . self::FAILED_DIR;
    }

    /**
     * Legt mehrere Aufträge an. Scheitert eine Datei, werden die bereits geschriebenen wieder entfernt
     * (alles oder nichts aus Sicht des Formulars).
     *
     * @param list<array{typ: string, formular: string, anfrage: string, payload: array<string, mixed>}> $jobs
     * @return list<string> Auftragskennungen
     */
    public function enqueueAll(array $jobs): array
    {
        $ids = [];
        try {
            foreach ($jobs as $job) {
                $ids[] = $this->enqueue($job['typ'], $job['formular'], $job['anfrage'], $job['payload'], count($ids));
            }
        } catch (Throwable $e) {
            foreach ($ids as $id) {
                @unlink($this->directory() . '/' . $id . self::SUFFIX);
            }
            throw $e;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(string $typ, string $formular, string $anfrage, array $payload, int $seq = 0): string
    {
        if (!in_array($typ, ['webhook', 'mail'], true)) {
            throw new \InvalidArgumentException('Unbekannter Auftragstyp.');
        }
        $this->ensureDirectory($this->directory());
        $now = Clock::now()->getTimestamp();
        // Sortierbar: Zeitpunkt, Reihenfolge innerhalb der Anfrage (Webhook vor den Mails), Zufall
        $id = sprintf('%010d-%02d-%s', $now, max(0, min(99, $seq)), bin2hex(random_bytes(8)));
        $this->write($this->directory() . '/' . $id . self::SUFFIX, [
            'id' => $id,
            'typ' => $typ,
            'formular' => $formular,
            'anfrage' => $anfrage,
            'attempts' => 0,
            'created_at' => $now,
            'next_attempt_at' => $now,
            'last_error' => null,
            'payload' => $payload,
        ]);

        return $id;
    }

    /**
     * Verarbeitet bis zu $limit fällige Aufträge.
     *
     * @return array{claimed: int, sent: int, retried: int, failed: int, postponed: int}
     */
    public function runOnce(int $limit = 20): array
    {
        $stats = ['claimed' => 0, 'sent' => 0, 'retried' => 0, 'failed' => 0, 'postponed' => 0];
        $warned = [];
        foreach ($this->files($this->directory()) as $path) {
            if ($stats['claimed'] >= $limit) {
                break;
            }
            $handle = @fopen($path, 'r');
            if ($handle === false) {
                continue;
            }
            try {
                if (!flock($handle, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                clearstatcache(true, $path);
                if (!is_file($path)) {
                    continue;
                }
                $job = $this->readHandle($handle);
                if ($job === null) {
                    $this->moveRaw($path);
                    $this->log->warning('Outbox-Datei nicht lesbar, nach fehlgeschlagen verschoben', ['auftrag' => basename($path, self::SUFFIX)]);
                    continue;
                }
                if ((int) $job['next_attempt_at'] > Clock::now()->getTimestamp()) {
                    continue;
                }
                $stats['claimed']++;
                $result = $this->process($path, $job, $warned);
                $stats[$result]++;
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, true>  $warned
     */
    private function process(string $path, array $job, array &$warned): string
    {
        $context = ['auftrag' => (string) $job['id'], 'typ' => (string) $job['typ'], 'formular' => (string) $job['formular'], 'anfrage' => (string) $job['anfrage']];

        $missing = $this->missingConfiguration($job);
        if ($missing !== null) {
            $job['next_attempt_at'] = Clock::now()->getTimestamp() + OutboxWorker::POSTPONE_MINUTES * 60;
            $job['last_error'] = 'Wartet auf Konfiguration: ' . $missing;
            $this->write($path, $job);
            if (!isset($warned[$missing])) {
                $this->log->warning('Outbox-Datei: Versand zurückgestellt, Konfiguration fehlt (' . $missing . ')', $context);
                $warned[$missing] = true;
            }

            return 'postponed';
        }

        try {
            $this->deliver($job);
        } catch (Throwable $e) {
            $attempts = (int) $job['attempts'] + 1;
            $error = OutboxWorker::sanitizeError($e);
            $job['attempts'] = $attempts;
            $job['last_error'] = $error;
            if ($attempts > count(OutboxWorker::BACKOFF_MINUTES)) {
                $this->ensureDirectory($this->failedDirectory());
                $target = $this->failedDirectory() . '/' . basename($path);
                $this->write($target, $job);
                @unlink($path);
                $this->log->warning('Outbox-Datei: endgültig fehlgeschlagen, nach fehlgeschlagen verschoben', $context + ['versuche' => $attempts, 'fehler' => $error]);

                return 'failed';
            }
            $delay = OutboxWorker::BACKOFF_MINUTES[$attempts - 1];
            $job['next_attempt_at'] = Clock::now()->getTimestamp() + $delay * 60;
            $this->write($path, $job);
            $this->log->warning('Outbox-Datei: Versand fehlgeschlagen, neuer Versuch in ' . $delay . ' Minuten', $context + ['versuche' => $attempts, 'fehler' => $error]);

            return 'retried';
        }

        @unlink($path);
        $anhang = $job['payload']['anhang'] ?? null;
        if (is_array($anhang) && is_string($anhang['pfad'] ?? null)) {
            $this->deleteAttachment($anhang['pfad']);
        }
        $this->log->info('Outbox-Datei: versendet', $context);

        return 'sent';
    }

    /**
     * @param array<string, mixed> $job
     */
    private function missingConfiguration(array $job): ?string
    {
        if ($job['typ'] === 'webhook') {
            return $this->webhook->missingConfiguration();
        }
        $missing = $this->mail->missingConfiguration();
        if ($missing !== null) {
            return $missing;
        }
        if (($job['payload']['to'] ?? null) === null && $this->recipient($job) === null) {
            return 'LEAD_NOTIFY_TO nicht gesetzt';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function recipient(array $job): ?string
    {
        $to = $job['payload']['to'] ?? null;
        if (is_string($to)) {
            return $to;
        }

        return ($job['payload']['empfaenger'] ?? 'lead') === 'bewerbung'
            ? $this->mail->bewerbungNotifyAddress()
            : $this->mail->leadNotifyAddress();
    }

    /**
     * @param array<string, mixed> $job
     */
    private function deliver(array $job): void
    {
        $payload = (array) $job['payload'];
        if ($job['typ'] === 'webhook') {
            $body = $payload['body'] ?? null;
            if (!is_string($body) || $body === '') {
                throw new \UnexpectedValueException('Webhook-Auftrag ohne Body.');
            }
            $this->webhook->send($body, (string) ($payload['event'] ?? 'anfrage.eingegangen'), (string) $job['id']);

            return;
        }
        $to = $this->recipient($job);
        if ($to === null) {
            throw new \UnexpectedValueException('Mail ohne Empfänger.');
        }
        $attachments = [];
        $anhang = $payload['anhang'] ?? null;
        if (is_array($anhang)) {
            $file = $this->uploadPath((string) ($anhang['pfad'] ?? ''));
            if ($file === null || !is_file($file)) {
                throw new \RuntimeException('Anhang des Auftrags fehlt.');
            }
            $attachments[] = [
                'name' => (string) ($anhang['name'] ?? 'bewerbung.pdf'),
                'mime' => (string) ($anhang['mime'] ?? 'application/pdf'),
                'content' => Crypto::decryptFile($file, $this->uploadKey),
            ];
        }
        $this->mail->send($to, (string) ($payload['subject'] ?? ''), (string) ($payload['html'] ?? ''), (string) ($payload['text'] ?? ''), $attachments);
    }

    /**
     * Löscht fehlgeschlagene Aufträge (und deren Bewerbungsanhänge) nach LEAD_RETENTION_DAYS, spätestens nach
     * 30 Tagen, verwaiste Bewerbungsdateien ohne Auftrag nach derselben Frist und liegengebliebene temporäre
     * Dateien nach einem Tag.
     *
     * @return array{fehlgeschlagen_geloescht: int, anhaenge_geloescht: int, temporaer_geloescht: int}
     */
    public function cleanup(bool $dryRun = false, ?int $now = null): array
    {
        $now ??= Clock::now()->getTimestamp();
        $cutoff = $now - $this->retentionDays() * 86400;
        $result = ['fehlgeschlagen_geloescht' => 0, 'anhaenge_geloescht' => 0, 'temporaer_geloescht' => 0];

        foreach ($this->files($this->failedDirectory()) as $path) {
            if ((int) @filemtime($path) >= $cutoff) {
                continue;
            }
            $job = $this->read($path);
            $result['fehlgeschlagen_geloescht']++;
            if ($dryRun) {
                continue;
            }
            @unlink($path);
            $anhang = $job['payload']['anhang'] ?? null;
            if (is_array($anhang) && is_string($anhang['pfad'] ?? null) && $this->deleteAttachment($anhang['pfad'])) {
                $result['anhaenge_geloescht']++;
            }
        }

        // Verwaiste Bewerbungsdateien: kein offener oder fehlgeschlagener Auftrag verweist mehr darauf
        $uploads = $this->basePath . '/' . self::UPLOAD_SUBDIR;
        $referenced = $this->referencedAttachments();
        foreach (glob($uploads . '/*.bin') ?: [] as $file) {
            $relative = self::UPLOAD_SUBDIR . '/' . basename($file);
            if (isset($referenced[$relative]) || (int) @filemtime($file) >= $cutoff) {
                continue;
            }
            $result['anhaenge_geloescht']++;
            if (!$dryRun) {
                @unlink($file);
            }
        }

        foreach ([$this->directory(), $this->failedDirectory()] as $dir) {
            foreach (glob($dir . '/.tmp-*') ?: [] as $tmp) {
                if ((int) @filemtime($tmp) < $now - 86400) {
                    $result['temporaer_geloescht']++;
                    if (!$dryRun) {
                        @unlink($tmp);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Ablageort einer Bewerbungsdatei zu einer Anfrage.
     *
     * @return array{0: string, 1: string} relativer Pfad (steht im Auftrag) und absoluter Pfad
     */
    public function attachmentPath(string $uuid): array
    {
        $relative = self::UPLOAD_SUBDIR . '/' . $uuid . '.bin';
        if ($this->uploadPath($relative) === null) {
            throw new \InvalidArgumentException('Ungültige Kennung.');
        }

        return [$relative, $this->basePath . '/' . $relative];
    }

    public function retentionDays(): int
    {
        $days = $this->retentionDays;

        return $days !== null && $days > 0 ? min($days, self::MAX_RETENTION_DAYS) : self::MAX_RETENTION_DAYS;
    }

    public function pendingCount(): int
    {
        return count($this->files($this->directory()));
    }

    public function failedCount(): int
    {
        return count($this->files($this->failedDirectory()));
    }

    /**
     * Entschlüsselter Auftrag (Tests, Diagnose). Enthält personenbezogene Daten: nie ausgeben oder protokollieren.
     *
     * @return array<string, mixed>|null
     */
    public function read(string $path): ?array
    {
        $raw = @file_get_contents($path);

        return $raw === false ? null : $this->decode($raw);
    }

    /**
     * @param resource $handle
     * @return array<string, mixed>|null
     */
    private function readHandle($handle): ?array
    {
        $raw = stream_get_contents($handle);

        return $raw === false ? null : $this->decode($raw);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $raw): ?array
    {
        try {
            $plain = Crypto::decrypt($raw, $this->key);
            $data = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
            sodium_memzero($plain);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) && isset($data['id'], $data['typ'], $data['payload']) ? $data : null;
    }

    /**
     * Schreibt verschlüsselt und atomar: temporäre Datei mit 0600 im Zielordner, danach rename.
     *
     * @param array<string, mixed> $job
     */
    private function write(string $path, array $job): void
    {
        $plain = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $cipher = Crypto::encrypt($plain, $this->key);
        sodium_memzero($plain);
        $tmp = dirname($path) . '/.tmp-' . bin2hex(random_bytes(8));
        $handle = @fopen($tmp, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Outbox-Datei konnte nicht angelegt werden (storage/outbox beschreibbar?).');
        }
        try {
            chmod($tmp, 0600);
            if (fwrite($handle, $cipher) !== strlen($cipher) || !fflush($handle)) {
                throw new \RuntimeException('Outbox-Datei konnte nicht vollständig geschrieben werden.');
            }
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($tmp);
            throw $e;
        }
        fclose($handle);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Outbox-Datei konnte nicht abgelegt werden.');
        }
    }

    private function moveRaw(string $path): void
    {
        $this->ensureDirectory($this->failedDirectory());
        $target = $this->failedDirectory() . '/' . basename($path);
        if (@rename($path, $target)) {
            @touch($target);
        }
    }

    /**
     * @return list<string>
     */
    private function files(string $dir): array
    {
        $files = glob($dir . '/*' . self::SUFFIX) ?: [];
        sort($files, SORT_STRING);

        return array_values(array_filter($files, 'is_file'));
    }

    /**
     * @return array<string, true>
     */
    private function referencedAttachments(): array
    {
        $refs = [];
        foreach (array_merge($this->files($this->directory()), $this->files($this->failedDirectory())) as $path) {
            $pfad = $this->read($path)['payload']['anhang']['pfad'] ?? null;
            if (is_string($pfad)) {
                $refs[$pfad] = true;
            }
        }

        return $refs;
    }

    private function uploadPath(string $relative): ?string
    {
        // Nur Dateien im Bewerbungsordner, keine Pfadbestandteile aus dem Auftrag übernehmen
        if (!preg_match('#^' . preg_quote(self::UPLOAD_SUBDIR, '#') . '/[a-f0-9-]{36}\.bin$#', $relative)) {
            return null;
        }

        return $this->basePath . '/' . $relative;
    }

    private function deleteAttachment(string $relative): bool
    {
        $file = $this->uploadPath($relative);

        return $file !== null && is_file($file) && @unlink($file);
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('storage/outbox ist nicht beschreibbar.');
        }
    }
}
