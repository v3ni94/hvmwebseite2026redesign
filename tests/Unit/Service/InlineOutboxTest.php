<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Service;

use Hvm\Http\Request;
use Hvm\Service\InlineOutbox;
use Hvm\Tests\Unit\TestCase;

/**
 * OUTBOX_MODE: worker (Standard) verarbeitet nie im Webprozess, inline nach POST sofort und bei GET gedrosselt.
 * Verarbeitung gegen die Datenbank: tests/Integration/InlineOutboxTest.php.
 */
final class InlineOutboxTest extends TestCase
{
    private function inline(string $mode, ?string $dbName = 'hvm_fiktiv'): InlineOutbox
    {
        $kernel = $this->kernel('staging', ['OUTBOX_MODE' => $mode, 'DB_NAME' => $dbName]);
        $dir = sys_get_temp_dir() . '/hvm-inline-' . bin2hex(random_bytes(4));
        $kernel->container()->set(InlineOutbox::class, static fn ($c): InlineOutbox => new InlineOutbox(
            $c,
            $kernel->config(),
            $c->get(\Hvm\Support\Log::class),
            $dir
        ));

        return $kernel->container()->get(InlineOutbox::class);
    }

    public function testWorkerModeIsDefaultAndNeverDue(): void
    {
        self::assertSame('worker', $this->kernel('staging')->config()->get('app.outbox_mode'));
        self::assertSame('worker', $this->kernel('staging', ['OUTBOX_MODE' => 'unsinn'])->config()->get('app.outbox_mode'));
        $inline = $this->inline('worker');
        self::assertFalse($inline->enabled());
        self::assertFalse($inline->due(Request::create('POST', '/angebot/')));
    }

    public function testFileModeIsDueWithoutDatabase(): void
    {
        $kernel = $this->kernel('staging', ['OUTBOX_MODE' => 'inline', 'STORAGE_MODE' => 'datei', 'DB_NAME' => null]);
        $inline = new InlineOutbox($kernel->container(), $kernel->config(), $kernel->container()->get(\Hvm\Support\Log::class), sys_get_temp_dir() . '/hvm-inline-' . bin2hex(random_bytes(4)));
        self::assertTrue($inline->enabled());
        self::assertTrue($inline->due(Request::create('POST', '/kontakt/')));
        self::assertFalse($inline->due(Request::create('POST', '/_einrichtung/')));
    }

    public function testInlineWithoutDatabaseIsNotDue(): void
    {
        $inline = $this->inline('inline', null);
        self::assertFalse($inline->due(Request::create('POST', '/angebot/')));
    }

    public function testInlineDueAfterPostAndThrottledOnGet(): void
    {
        $inline = $this->inline('inline');
        self::assertTrue($inline->enabled());
        self::assertTrue($inline->due(Request::create('POST', '/angebot/')));
        // ohne bisherigen Lauf ist auch GET fällig
        self::assertTrue($inline->due(Request::create('GET', '/')));
    }

    public function testRunFailureOnlyLogsAndReturnsNull(): void
    {
        // Datenbank nicht erreichbar: kein Absturz, nur Log, danach GET gedrosselt
        $inline = $this->inline('inline');
        self::assertNull($inline->run());
        self::assertFalse($inline->due(Request::create('GET', '/')));
        self::assertTrue($inline->due(Request::create('GET', '/'), time() + InlineOutbox::GET_INTERVAL));
    }

    public function testKernelTerminateIsSilentInWorkerMode(): void
    {
        $kernel = $this->kernel('staging');
        $kernel->handle(Request::create('GET', '/'));
        $kernel->terminate();
        $this->addToAssertionCount(1);
    }
}
