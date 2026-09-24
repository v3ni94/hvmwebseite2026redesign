<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Service;

use Hvm\Security\Crypto;
use Hvm\Service\FileOutbox;
use Hvm\Service\MailService;
use Hvm\Service\OutboxWorker;
use Hvm\Service\WebhookService;
use Hvm\Support\Clock;
use Hvm\Support\Config;
use Hvm\Support\Log;
use Hvm\Tests\Unit\TestCase;

/**
 * Outbox ohne Datenbank (STORAGE_MODE=datei): Verschlüsselung, Dateirechte, Signatur, Backoff, Verschieben nach
 * fehlgeschlagen, Löschen nach Erfolg, Bewerbungsanhang, Aufräumen, Sperre. Alle Daten fiktiv (example.org).
 */
final class FileOutboxTest extends TestCase
{
    private const SECRET = 'fiktives-webhook-geheimnis-nur-fuer-tests-0123456789';

    private string $base = '';

    /** @var list<array<string, mixed>> */
    private array $mails = [];

    /** @var list<array{url: string, headers: array<string, string>, body: string}> */
    private array $hooks = [];

    private int $hookStatus = 200;

    private bool $mailFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/hvm-fileoutbox-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/storage/logs', 0775, true);
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        self::removeDir($this->base);
        parent::tearDown();
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $dir . '/' . $entry;
                is_dir($path) ? self::removeDir($path) : unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * @param array<string, mixed> $app
     */
    private function outbox(array $app = [], ?int $retention = null): FileOutbox
    {
        $config = new Config(['app' => array_replace_recursive([
            'env' => 'staging',
            'mail' => ['host' => 'smtp.example.org', 'from' => 'website@example.org'],
            'lead_notify_to' => 'leads@example.org',
            'bewerbung_notify_to' => 'bewerbung@example.org',
            'n8n' => ['webhook_url' => 'https://n8n.example.org/webhook/anfragen', 'webhook_secret' => self::SECRET],
        ], $app)]);
        $mail = new MailService($config, function (array $message): void {
            if ($this->mailFails) {
                throw new \RuntimeException('SMTP nicht erreichbar für kontakt@example.org');
            }
            $this->mails[] = $message;
        });
        $webhook = new WebhookService($config, function (string $url, array $headers, string $body): int {
            $this->hooks[] = ['url' => $url, 'headers' => $headers, 'body' => $body];

            return $this->hookStatus;
        });

        return new FileOutbox(
            $this->base . '/storage/outbox',
            str_repeat('o', 32),
            $mail,
            $webhook,
            new Log($this->base . '/storage/logs'),
            $this->base,
            str_repeat('u', 32),
            $retention
        );
    }

    private function logText(): string
    {
        return (string) @file_get_contents($this->base . '/storage/logs/app.log');
    }

    public function testJobIsEncryptedWrittenAtomicallyWith0600(): void
    {
        $outbox = $this->outbox();
        $id = $outbox->enqueue('mail', 'kontakt', 'a1b2', ['to' => 'erika.beispiel@example.org', 'subject' => 'Test', 'html' => '<p>Erika Beispiel</p>', 'text' => 'Erika Beispiel']);
        $path = $this->base . '/storage/outbox/' . $id . FileOutbox::SUFFIX;

        self::assertFileExists($path);
        self::assertSame('0600', substr(sprintf('%o', fileperms($path)), -4));
        $raw = (string) file_get_contents($path);
        self::assertStringNotContainsString('example.org', $raw);
        self::assertStringNotContainsString('Erika', $raw);
        $plain = json_decode(Crypto::decrypt($raw, str_repeat('o', 32)), true);
        self::assertSame('erika.beispiel@example.org', $plain['payload']['to']);
        self::assertSame([], glob($this->base . '/storage/outbox/.tmp-*'), 'keine temporären Dateien');
        self::assertSame(['id' => $id, 'typ' => 'mail', 'formular' => 'kontakt'], array_intersect_key((array) $outbox->read($path), ['id' => 1, 'typ' => 1, 'formular' => 1]));
    }

    public function testWebhookIsSignedAndFileDeletedAfterSuccess(): void
    {
        $outbox = $this->outbox();
        $body = '{"typ":"angebot","uuid":"11111111-2222-4333-8444-555555555555"}';
        $id = $outbox->enqueue('webhook', 'angebot', '11111111-2222-4333-8444-555555555555', ['event' => 'angebot.eingegangen', 'body' => $body]);

        $stats = $outbox->runOnce();
        self::assertSame(1, $stats['sent']);
        self::assertCount(1, $this->hooks);
        $hook = $this->hooks[0];
        self::assertSame($body, $hook['body']);
        self::assertSame('angebot.eingegangen', $hook['headers']['X-HVM-Event']);
        self::assertSame($id, $hook['headers']['X-HVM-Delivery']);
        self::assertTrue(WebhookService::verify($body, $hook['headers']['X-HVM-Timestamp'], $hook['headers']['X-HVM-Signature'], self::SECRET));
        self::assertSame(0, $outbox->pendingCount());
    }

    public function testRetryWithBackoffThenMoveToFailed(): void
    {
        Clock::freeze('2026-09-24 10:00:00');
        $outbox = $this->outbox();
        $this->hookStatus = 500;
        $outbox->enqueue('webhook', 'angebot', 'u1', ['event' => 'angebot.eingegangen', 'body' => '{"typ":"angebot"}']);

        $minute = 0;
        foreach (OutboxWorker::BACKOFF_MINUTES as $i => $delay) {
            $stats = $outbox->runOnce();
            self::assertSame(1, $stats['retried'], 'Versuch ' . ($i + 1));
            // vor Ablauf des Backoffs nicht erneut
            self::assertSame(0, $outbox->runOnce()['claimed']);
            $minute += $delay;
            Clock::freeze((new \DateTimeImmutable('2026-09-24 10:00:00'))->modify('+' . $minute . ' minutes')->format('Y-m-d H:i:s'));
        }
        $stats = $outbox->runOnce();
        self::assertSame(1, $stats['failed']);
        self::assertCount(6, $this->hooks);
        self::assertSame(0, $outbox->pendingCount());
        self::assertSame(1, $outbox->failedCount());
        $failed = glob($this->base . '/storage/outbox/' . FileOutbox::FAILED_DIR . '/*' . FileOutbox::SUFFIX) ?: [];
        self::assertSame('0600', substr(sprintf('%o', fileperms($failed[0])), -4));
        self::assertStringContainsString('endgültig fehlgeschlagen', $this->logText());
    }

    public function testFailureLogContainsNoPersonalData(): void
    {
        Clock::freeze('2026-09-24 10:00:00');
        $this->mailFails = true;
        $outbox = $this->outbox();
        $outbox->enqueue('mail', 'kontakt', 'u2', ['to' => 'kontakt@example.org', 'subject' => 'Ihre Nachricht', 'html' => 'Max Beispiel', 'text' => 'Max Beispiel']);
        self::assertSame(1, $outbox->runOnce()['retried']);
        $log = $this->logText();
        self::assertStringNotContainsString('kontakt@example.org', $log);
        self::assertStringNotContainsString('Max Beispiel', $log);
        self::assertStringContainsString('[email entfernt]', $log);
    }

    public function testMissingConfigurationPostponesWithoutCountingAttempt(): void
    {
        Clock::freeze('2026-09-24 10:00:00');
        $outbox = $this->outbox(['n8n' => ['webhook_url' => null]]);
        $id = $outbox->enqueue('webhook', 'angebot', 'u3', ['event' => 'angebot.eingegangen', 'body' => '{}']);
        self::assertSame(1, $outbox->runOnce()['postponed']);
        $job = $outbox->read($this->base . '/storage/outbox/' . $id . FileOutbox::SUFFIX);
        self::assertSame(0, $job['attempts']);
        self::assertSame([], $this->hooks);
    }

    public function testApplicationAttachmentIsMailedToBewerbungRecipientAndDeleted(): void
    {
        $outbox = $this->outbox();
        $uuid = '0a1b2c3d-1111-4222-8333-444455556666';
        [$relative, $absolute] = $outbox->attachmentPath($uuid);
        mkdir(dirname($absolute), 0700, true);
        Crypto::encryptToFile("%PDF-1.4\nFiktiv", $absolute, str_repeat('u', 32));
        $outbox->enqueue('mail', 'bewerbung', $uuid, [
            'to' => null, 'empfaenger' => 'bewerbung', 'subject' => 'Neue Bewerbung', 'html' => '<p>x</p>', 'text' => 'x',
            'anhang' => ['pfad' => $relative, 'name' => 'bewerbung-0a1b2c3d.pdf', 'mime' => 'application/pdf'],
        ]);

        self::assertSame(1, $outbox->runOnce()['sent']);
        self::assertSame('bewerbung@example.org', $this->mails[0]['to']);
        self::assertSame("%PDF-1.4\nFiktiv", $this->mails[0]['attachments'][0]['content']);
        self::assertSame('bewerbung-0a1b2c3d.pdf', $this->mails[0]['attachments'][0]['name']);
        self::assertFileDoesNotExist($absolute);
        self::assertSame(0, $outbox->pendingCount());
    }

    public function testBewerbungRecipientFallsBackToLeadNotify(): void
    {
        $outbox = $this->outbox(['bewerbung_notify_to' => '']);
        $outbox->enqueue('mail', 'bewerbung', 'u4', ['to' => null, 'empfaenger' => 'bewerbung', 'subject' => 'S', 'html' => 'h', 'text' => 't']);
        $outbox->runOnce();
        self::assertSame('leads@example.org', $this->mails[0]['to']);
    }

    public function testCleanupDeletesFailedJobsAfterRetentionMax30Days(): void
    {
        Clock::freeze('2026-09-24 10:00:00');
        $outbox = $this->outbox([], 365);
        self::assertSame(30, $outbox->retentionDays(), 'höchstens 30 Tage');
        self::assertSame(7, $this->outbox([], 7)->retentionDays());
        self::assertSame(30, $this->outbox([], null)->retentionDays());

        $this->hookStatus = 500;
        $outbox->enqueue('webhook', 'angebot', 'u5', ['event' => 'e', 'body' => '{}']);
        for ($i = 0; $i <= count(OutboxWorker::BACKOFF_MINUTES); $i++) {
            Clock::freeze((new \DateTimeImmutable('2026-09-24 10:00:00'))->modify('+' . ($i * 300) . ' minutes')->format('Y-m-d H:i:s'));
            $outbox->runOnce();
        }
        self::assertSame(1, $outbox->failedCount());
        $now = time();
        self::assertSame(0, $outbox->cleanup(false, $now + 29 * 86400)['fehlgeschlagen_geloescht']);
        self::assertSame(1, $outbox->cleanup(true, $now + 31 * 86400)['fehlgeschlagen_geloescht'], 'Trockenlauf zählt');
        self::assertSame(1, $outbox->failedCount());
        self::assertSame(1, $outbox->cleanup(false, $now + 31 * 86400)['fehlgeschlagen_geloescht']);
        self::assertSame(0, $outbox->failedCount());
    }

    public function testLockedFileIsSkipped(): void
    {
        $outbox = $this->outbox();
        $id = $outbox->enqueue('webhook', 'angebot', 'u6', ['event' => 'e', 'body' => '{}']);
        $handle = fopen($this->base . '/storage/outbox/' . $id . FileOutbox::SUFFIX, 'r');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        try {
            self::assertSame(0, $outbox->runOnce()['claimed']);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        self::assertSame(1, $outbox->runOnce()['sent']);
    }

    public function testUnreadableFileIsMovedToFailed(): void
    {
        $outbox = $this->outbox();
        mkdir($this->base . '/storage/outbox', 0700, true);
        file_put_contents($this->base . '/storage/outbox/0000000001-kaputt.job', 'kein gültiger Inhalt');
        $outbox->runOnce();
        self::assertSame(0, $outbox->pendingCount());
        self::assertSame(1, $outbox->failedCount());
    }
}
