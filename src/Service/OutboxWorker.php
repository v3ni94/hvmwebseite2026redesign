<?php

declare(strict_types=1);

namespace Hvm\Service;

use Hvm\Repository\OutboxRepository;
use Hvm\Support\Clock;
use Hvm\Support\Log;
use Throwable;

/**
 * Verarbeitet fällige Outbox-Einträge (Webhook und Mail).
 *
 * - Wiederholung mit exponentiellem Backoff: 1, 5, 15, 60, 240 Minuten, danach Status failed.
 * - Sperre gegen Doppelverarbeitung über Status-Claim (OutboxRepository::claim).
 * - Fehlt die Konfiguration (SMTP, LEAD_NOTIFY_TO, n8n), bleibt der Eintrag pending und wird nach
 *   POSTPONE_MINUTES erneut geprüft, ohne als Fehlversuch zu zählen. Hinweis im Log, kein Absturz.
 * - Nach erfolgreichem Versand wird die Payload geleert.
 * - Fehlermeldungen werden vor dem Speichern um E-Mail-Adressen und Telefonnummern bereinigt.
 */
final class OutboxWorker
{
    public const BACKOFF_MINUTES = [1, 5, 15, 60, 240];
    public const POSTPONE_MINUTES = 15;

    public function __construct(
        private readonly OutboxRepository $outbox,
        private readonly MailService $mail,
        private readonly WebhookService $webhook,
        private readonly Log $log,
    ) {
    }

    /**
     * @return array{claimed: int, sent: int, retried: int, failed: int, postponed: int}
     */
    public function runOnce(int $limit = 20): array
    {
        $token = bin2hex(random_bytes(16));
        $stats = ['claimed' => 0, 'sent' => 0, 'retried' => 0, 'failed' => 0, 'postponed' => 0];
        $warned = [];

        foreach ($this->outbox->claim($token, $limit) as $entry) {
            $stats['claimed']++;
            $context = ['outbox' => $entry['id'], 'typ' => $entry['typ'], 'lead' => (string) ($entry['payload']['lead_uuid'] ?? '')];

            $missing = $this->missingConfiguration($entry);
            if ($missing !== null) {
                $this->outbox->postpone($entry['id'], $token, Clock::now()->modify('+' . self::POSTPONE_MINUTES . ' minutes'), 'Wartet auf Konfiguration: ' . $missing);
                if (!isset($warned[$missing])) {
                    $this->log->warning('Outbox: Versand zurückgestellt, Konfiguration fehlt (' . $missing . ')', $context);
                    $warned[$missing] = true;
                }
                $stats['postponed']++;
                continue;
            }

            try {
                $this->deliver($entry);
                $this->outbox->markSent($entry['id'], $token);
                $this->log->info('Outbox: versendet', $context);
                $stats['sent']++;
            } catch (Throwable $e) {
                $attempts = $entry['attempts'] + 1;
                $error = self::sanitizeError($e);
                if ($attempts > count(self::BACKOFF_MINUTES)) {
                    $this->outbox->markFailed($entry['id'], $token, $attempts, $error);
                    $this->log->error('Outbox: endgültig fehlgeschlagen', $context + ['versuche' => $attempts, 'fehler' => $error]);
                    $stats['failed']++;
                    continue;
                }
                $delay = self::BACKOFF_MINUTES[$attempts - 1];
                $this->outbox->reschedule($entry['id'], $token, $attempts, Clock::now()->modify('+' . $delay . ' minutes'), $error);
                $this->log->warning('Outbox: Versand fehlgeschlagen, neuer Versuch in ' . $delay . ' Minuten', $context + ['versuche' => $attempts, 'fehler' => $error]);
                $stats['retried']++;
            }
        }

        return $stats;
    }

    /**
     * @param array{id: int, typ: string, lead_id: ?int, payload: array<string, mixed>, attempts: int} $entry
     */
    private function missingConfiguration(array $entry): ?string
    {
        if ($entry['typ'] === 'webhook') {
            return $this->webhook->missingConfiguration();
        }
        $missing = $this->mail->missingConfiguration();
        if ($missing !== null) {
            return $missing;
        }
        if (($entry['payload']['to'] ?? null) === null && $this->mail->leadNotifyAddress() === null) {
            return 'LEAD_NOTIFY_TO nicht gesetzt';
        }

        return null;
    }

    /**
     * @param array{id: int, typ: string, lead_id: ?int, payload: array<string, mixed>, attempts: int} $entry
     */
    private function deliver(array $entry): void
    {
        $payload = $entry['payload'];
        if ($entry['typ'] === 'webhook') {
            $body = $payload['body'] ?? null;
            if (!is_string($body) || $body === '') {
                throw new \UnexpectedValueException('Webhook-Payload ohne Body.');
            }
            $this->webhook->send($body, (string) ($payload['event'] ?? 'lead.created'), (string) $entry['id']);

            return;
        }
        if ($entry['typ'] === 'mail') {
            $to = $payload['to'] ?? $this->mail->leadNotifyAddress();
            if (!is_string($to)) {
                throw new \UnexpectedValueException('Mail ohne Empfänger.');
            }
            $this->mail->send($to, (string) ($payload['subject'] ?? ''), (string) ($payload['html'] ?? ''), (string) ($payload['text'] ?? ''));

            return;
        }

        throw new \UnexpectedValueException('Unbekannter Outbox-Typ.');
    }

    public static function sanitizeError(Throwable $e): string
    {
        $message = Log::redact(get_class($e) . ': ' . $e->getMessage());
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        return mb_substr($message, 0, 300);
    }
}
