<?php

declare(strict_types=1);

namespace Hvm\Tests\Integration;

use Hvm\Repository\OutboxRepository;
use Hvm\Service\MailService;
use Hvm\Service\OutboxWorker;
use Hvm\Service\WebhookService;
use Hvm\Support\Clock;
use Hvm\Support\Config;
use Hvm\Support\Log;

/**
 * Worker mit Fake-Transporten: Versand, Backoff, fehlende Konfiguration, Sperre gegen Doppelverarbeitung.
 */
final class OutboxWorkerTest extends IntegrationTestCase
{
    private const SECRET = 'fiktives-geheimnis';

    /** @var list<array<string, string>> */
    private array $mails = [];

    /** @var list<array{url: string, headers: array<string, string>, body: string}> */
    private array $hooks = [];

    private function config(bool $configured = true): Config
    {
        return new Config(['app' => [
            'mail' => $configured
                ? ['host' => 'smtp.example.org', 'from' => 'website@example.org', 'from_name' => 'Test']
                : ['host' => null, 'from' => null],
            'lead_notify_to' => $configured ? 'leads@example.org' : null,
            'n8n' => $configured
                ? ['webhook_url' => 'https://n8n.example.org/webhook/lead', 'webhook_secret' => self::SECRET]
                : ['webhook_url' => null, 'webhook_secret' => null],
        ]]);
    }

    private function worker(bool $configured = true, int $webhookStatus = 200, ?\Throwable $mailError = null): OutboxWorker
    {
        $config = $this->config($configured);
        $mail = new MailService($config, function (array $message) use ($mailError): void {
            if ($mailError !== null) {
                throw $mailError;
            }
            $this->mails[] = $message;
        });
        $webhook = new WebhookService($config, function (string $url, array $headers, string $body) use ($webhookStatus): int {
            $this->hooks[] = compact('url', 'headers', 'body');

            return $webhookStatus;
        });

        return new OutboxWorker(new OutboxRepository($this->db()), $mail, $webhook, new Log(sys_get_temp_dir() . '/hvm-test-logs'));
    }

    private function outbox(): OutboxRepository
    {
        return new OutboxRepository($this->db());
    }

    public function testDeliversAndClearsPayload(): void
    {
        Clock::freeze('2026-09-23 10:00:00');
        $webhookId = $this->outbox()->enqueue('webhook', ['event' => 'lead.created', 'lead_uuid' => 'u1', 'body' => '{"lead":{"uuid":"u1"}}']);
        $notifyId = $this->outbox()->enqueue('mail', ['to' => null, 'subject' => 'Neue Verwaltungsanfrage', 'html' => '<p>x</p>', 'text' => 'x']);
        $confirmId = $this->outbox()->enqueue('mail', ['to' => 'kunde@example.org', 'subject' => 'Bestätigung', 'html' => '<p>y</p>', 'text' => 'y']);

        $stats = $this->worker()->runOnce();
        self::assertSame(3, $stats['claimed']);
        self::assertSame(3, $stats['sent']);

        foreach ([$webhookId, $notifyId, $confirmId] as $id) {
            $row = $this->outbox()->find($id);
            self::assertSame('sent', $row['status']);
            self::assertNull($row['payload'], 'Payload nach Versand geleert');
            self::assertSame('2026-09-23 10:00:00', $row['sent_at']);
            self::assertNull($row['locked_by']);
        }

        self::assertSame(['leads@example.org', 'kunde@example.org'], array_column($this->mails, 'to'));
        self::assertCount(1, $this->hooks);
        $hook = $this->hooks[0];
        self::assertTrue(WebhookService::verify($hook['body'], $hook['headers']['X-HVM-Timestamp'], $hook['headers']['X-HVM-Signature'], self::SECRET));
        self::assertSame((string) $webhookId, $hook['headers']['X-HVM-Delivery']);

        self::assertSame(0, $this->worker()->runOnce()['claimed'], 'nichts doppelt');
    }

    public function testExponentialBackoffThenFailed(): void
    {
        Clock::freeze('2026-09-23 10:00:00');
        $id = $this->outbox()->enqueue('webhook', ['event' => 'lead.created', 'body' => '{}']);
        $worker = $this->worker(true, 503);
        $now = new \DateTimeImmutable('2026-09-23 10:00:00', new \DateTimeZone('UTC'));

        foreach (OutboxWorker::BACKOFF_MINUTES as $versuch => $minuten) {
            $stats = $worker->runOnce();
            self::assertSame(1, $stats['retried'], 'Versuch ' . ($versuch + 1));
            $row = $this->outbox()->find($id);
            self::assertSame('pending', $row['status']);
            self::assertSame($versuch + 1, (int) $row['attempts']);
            self::assertSame($now->modify('+' . $minuten . ' minutes')->format('Y-m-d H:i:s'), $row['next_attempt_at']);
            self::assertStringContainsString('HTTP 503', (string) $row['last_error']);

            // vor Fälligkeit passiert nichts
            Clock::freeze($now->modify('+' . $minuten . ' minutes')->modify('-1 second'));
            self::assertSame(0, $worker->runOnce()['claimed']);

            $now = $now->modify('+' . $minuten . ' minutes');
            Clock::freeze($now);
        }

        $stats = $worker->runOnce();
        self::assertSame(1, $stats['failed']);
        $row = $this->outbox()->find($id);
        self::assertSame('failed', $row['status']);
        self::assertSame(count(OutboxWorker::BACKOFF_MINUTES) + 1, (int) $row['attempts']);
        self::assertSame(0, $worker->runOnce()['claimed']);
    }

    public function testErrorMessagesAreStoredWithoutPersonalData(): void
    {
        Clock::freeze('2026-09-23 10:00:00');
        $id = $this->outbox()->enqueue('mail', ['to' => 'kunde@example.org', 'subject' => 's', 'html' => 'h', 'text' => 't']);
        $this->worker(true, 200, new \RuntimeException("SMTP Error: recipient kunde@example.org rejected\nTelefon 0211 1234567"))->runOnce();
        $error = (string) $this->outbox()->find($id)['last_error'];
        self::assertStringNotContainsString('kunde@example.org', $error);
        self::assertStringNotContainsString('1234567', $error);
        self::assertStringContainsString('[email entfernt]', $error);
    }

    public function testMissingConfigurationKeepsEntriesPending(): void
    {
        Clock::freeze('2026-09-23 10:00:00');
        $a = $this->outbox()->enqueue('webhook', ['body' => '{}']);
        $b = $this->outbox()->enqueue('mail', ['to' => null, 'subject' => 's', 'html' => 'h', 'text' => 't']);

        $stats = $this->worker(false)->runOnce();
        self::assertSame(2, $stats['postponed']);
        foreach ([$a, $b] as $id) {
            $row = $this->outbox()->find($id);
            self::assertSame('pending', $row['status']);
            self::assertSame(0, (int) $row['attempts'], 'fehlende Konfiguration zählt nicht als Fehlversuch');
            self::assertNotNull($row['payload']);
            self::assertStringContainsString('Wartet auf Konfiguration', (string) $row['last_error']);
            self::assertSame('2026-09-23 10:15:00', $row['next_attempt_at']);
        }
        self::assertSame([], $this->mails);
        self::assertSame([], $this->hooks);
    }

    public function testClaimPreventsDoubleProcessing(): void
    {
        Clock::freeze('2026-09-23 10:00:00');
        $this->outbox()->enqueue('webhook', ['body' => '{}']);
        $this->outbox()->enqueue('webhook', ['body' => '{}']);

        $first = $this->outbox()->claim(str_repeat('a', 32), 10, 300);
        self::assertCount(2, $first);
        self::assertSame([], $this->outbox()->claim(str_repeat('b', 32), 10, 300), 'gesperrte Einträge nicht erneut beanspruchen');

        // abgestürzter Worker: Sperre läuft ab
        Clock::freeze('2026-09-23 10:05:01');
        self::assertCount(2, $this->outbox()->claim(str_repeat('b', 32), 10, 300));
    }

    public function testWorkerScriptRunsOnceWithoutConfiguration(): void
    {
        $this->outbox()->enqueue('webhook', ['body' => '{}']);
        [$code, $output] = self::runPhp('bin/worker.php', ['--once'], [
            'N8N_WEBHOOK_URL' => '',
            'N8N_WEBHOOK_SECRET' => '',
            'MAIL_HOST' => '',
            'APP_ENV' => 'development',
        ]);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('1 zurückgestellt', $output);
        self::assertSame('pending', $this->row('SELECT status FROM outbox')['status']);
    }
}
