<?php

declare(strict_types=1);

namespace Hvm\Tests\Integration;

use Hvm\Http\Kernel;
use Hvm\Http\Request;
use Hvm\Repository\OutboxRepository;
use Hvm\Service\InlineOutbox;
use Hvm\Service\MailService;
use Hvm\Support\Config;
use Hvm\Support\Log;

/**
 * OUTBOX_MODE=inline: Verarbeitung nach der Antwort (Kernel::terminate) mit Fake-SMTP, höchstens 5 Einträge je Lauf,
 * Sperre gegen parallele Läufe.
 */
final class InlineOutboxTest extends IntegrationTestCase
{
    /** @var list<array<string, string>> */
    private array $mails = [];

    private string $state = '';

    protected function tearDown(): void
    {
        foreach (glob($this->state . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->state);
        parent::tearDown();
    }

    private function inlineKernel(string $mode = 'inline'): Kernel
    {
        $kernel = $this->kernel([
            'OUTBOX_MODE' => $mode,
            'MAIL_HOST' => 'smtp.example.org',
            'MAIL_FROM' => 'website@example.org',
            'LEAD_NOTIFY_TO' => 'leads@example.org',
        ]);
        $this->state = sys_get_temp_dir() . '/hvm-inline-int-' . bin2hex(random_bytes(4));
        $c = $kernel->container();
        $c->set(MailService::class, fn ($c): MailService => new MailService($c->get(Config::class), function (array $message): void {
            $this->mails[] = $message;
        }));
        $state = $this->state;
        $c->set(InlineOutbox::class, static fn ($c): InlineOutbox => new InlineOutbox($c, $c->get(Config::class), $c->get(Log::class), $state));

        return $kernel;
    }

    private function enqueue(int $count): void
    {
        $outbox = new OutboxRepository($this->db());
        for ($i = 0; $i < $count; $i++) {
            $outbox->enqueue('mail', ['subject' => 'Fiktive Anfrage ' . $i, 'html' => '<p>Test</p>', 'text' => 'Test']);
        }
    }

    public function testTerminateAfterPostSendsUpToFiveEntries(): void
    {
        $this->enqueue(7);
        $kernel = $this->inlineKernel();
        $kernel->terminate(Request::create('POST', '/angebot/'));

        self::assertCount(InlineOutbox::LIMIT, $this->mails);
        self::assertSame('leads@example.org', $this->mails[0]['to'] ?? null);
        self::assertSame(5, $this->countRows('outbox', 'status = ?', ['sent']));
        self::assertSame(2, $this->countRows('outbox', 'status = ?', ['pending']));
    }

    public function testWorkerModeLeavesQueueForWorker(): void
    {
        $this->enqueue(1);
        $this->inlineKernel('worker')->terminate(Request::create('POST', '/angebot/'));
        self::assertSame([], $this->mails);
        self::assertSame(1, $this->countRows('outbox', 'status = ?', ['pending']));
    }

    public function testParallelRunIsSkipped(): void
    {
        $this->enqueue(1);
        $kernel = $this->inlineKernel();
        mkdir($this->state, 0775, true);
        $handle = fopen($this->state . '/outbox-inline.lock', 'c');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        try {
            self::assertNull($kernel->container()->get(InlineOutbox::class)->run());
            self::assertSame([], $this->mails);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        self::assertNotNull($kernel->container()->get(InlineOutbox::class)->run());
        self::assertCount(1, $this->mails);
    }
}
