<?php

declare(strict_types=1);

namespace Hvm\Tests\Integration;

use Hvm\Tests\Unit\DateiModus;

/**
 * Dateimodus bei vorhandener Datenbank: Formulare schreiben keine einzige Zeile (leads, contact_requests,
 * job_applications, outbox, rate_limits), der Versand läuft vollständig über storage/outbox.
 * bin/worker.php und bin/retention.php arbeiten im Dateimodus ohne Datenbank.
 */
final class DateiModusFlowTest extends FormularIntegrationTestCase
{
    use DateiModus;

    protected function tearDown(): void
    {
        $this->entferneDateiBase();
        parent::tearDown();
    }

    public function testFormsStoreNoDatabaseRows(): void
    {
        $env = self::dateiEnv();
        unset($env['DB_HOST'], $env['DB_NAME']);
        $kernel = $this->dateiKernel($this->kernel($env));

        self::assertSame(303, $this->sende($kernel, '/angebot/', 'angebot', self::angebotDaten())->status());
        self::assertSame(303, $this->sende($kernel, '/kontakt/', 'kontakt', self::kontaktDaten())->status());
        [$daten, $datei] = $this->bewerbungDaten();
        self::assertSame(303, $this->sende($kernel, '/karriere/bewerbung/', 'bewerbung', $daten, $datei)->status());

        foreach (['leads', 'lead_events', 'contact_requests', 'job_applications', 'outbox', 'rate_limits'] as $table) {
            self::assertSame(0, $this->countRows($table), $table);
        }
        self::assertCount(3, $this->webhooks);
        self::assertSame(['angebot', 'kontakt', 'bewerbung'], array_map(static fn (array $h): string => json_decode($h['body'], true)['typ'], $this->webhooks));
        self::assertCount(6, $this->gesendeteMails);
        self::assertSame([], $this->outboxDateien());
    }

    public function testWorkerAndRetentionRunWithoutDatabaseInFileMode(): void
    {
        $env = ['STORAGE_MODE' => 'datei', 'DB_HOST' => '192.0.2.1', 'DB_NAME' => 'hvm_fiktiv', 'DB_PASSWORD' => 'falsch'];
        [$code, $output] = self::runPhp('bin/worker.php', ['--once'], $env);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Outbox:', $output);

        [$code, $output] = self::runPhp('bin/retention.php', ['--dry-run'], $env);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Löschlauf Dateimodus (Trockenlauf, keine Änderungen): Frist 30 Tage', $output);
    }
}
