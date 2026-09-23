<?php

declare(strict_types=1);

namespace Hvm\Tests\Integration;

use Hvm\Repository\OutboxRepository;
use Hvm\Service\Retention;
use Hvm\Support\Clock;
use Hvm\Support\Uuid;

/**
 * Löschlauf: Trockenlauf ändert nichts, danach Löschung bzw. Anonymisierung nach Frist.
 */
final class RetentionTest extends AdminTestCase
{
    private string $uploadDir = '';

    protected function tearDown(): void
    {
        if ($this->uploadDir !== '' && is_dir($this->uploadDir)) {
            array_map('unlink', glob($this->uploadDir . '/*') ?: []);
            rmdir($this->uploadDir);
        }
        parent::tearDown();
    }

    private function retention(?string $days = '90'): Retention
    {
        return $this->kernel(['LEAD_RETENTION_DAYS' => $days])->container()->get(Retention::class);
    }

    /**
     * @return array{spamAlt: int, verlorenAlt: int, verlorenNeu: int, neuAlt: int, datei: string}
     */
    private function seed(): array
    {
        $alt = '2026-01-01 10:00:00';
        $neu = '2026-09-01 10:00:00';
        $spamAlt = $this->insertLead(['status' => 'spam', 'created_at' => $alt, 'updated_at' => $alt]);
        $verlorenAlt = $this->insertLead(['status' => 'verloren', 'created_at' => $alt, 'updated_at' => $alt, 'notes' => 'Fiktive Notiz', 'region' => 'Musterregion']);
        $verlorenNeu = $this->insertLead(['status' => 'verloren', 'created_at' => $alt, 'updated_at' => $alt]);
        $neuAlt = $this->insertLead(['status' => 'neu', 'created_at' => $alt, 'updated_at' => $alt]);
        // verlorenNeu: Statuswechsel erst kürzlich, zählt ab dem Ereignis
        $this->db()->prepare("INSERT INTO lead_events (lead_id, typ, von_status, nach_status, created_at) VALUES (?, 'status', 'neu', 'verloren', ?)")->execute([$verlorenNeu, $neu]);
        $this->db()->prepare("INSERT INTO lead_events (lead_id, typ, notiz, created_at) VALUES (?, 'notiz', 'Fiktive Notiz im Verlauf', ?)")->execute([$verlorenAlt, $alt]);
        $outbox = new OutboxRepository($this->db());
        $outbox->enqueue('mail', ['to' => 'erika.beispiel@example.org'], $spamAlt);
        $outbox->enqueue('mail', ['to' => 'erika.beispiel@example.org'], $verlorenAlt);
        $this->db()->exec("UPDATE outbox SET created_at = '2026-01-01 10:00:00'");
        $this->db()->prepare("INSERT INTO outbox (typ, payload, status, created_at) VALUES ('mail', '{\"to\":\"kontakt@example.org\"}', 'failed', ?)")->execute([$alt]);

        $this->db()->prepare("INSERT INTO contact_requests (uuid, anliegen, email, status, created_at, updated_at) VALUES (?, 'verkauf', 'kontakt@example.org', ?, ?, ?)")
            ->execute([Uuid::v4(), 'erledigt', $alt, $alt]);
        $this->db()->prepare("INSERT INTO contact_requests (uuid, anliegen, email, status, created_at, updated_at) VALUES (?, 'verkauf', 'kontakt@example.org', ?, ?, ?)")
            ->execute([Uuid::v4(), 'in_bearbeitung', $alt, $alt]);

        $rel = 'storage/uploads/test-retention-' . bin2hex(random_bytes(4));
        $this->uploadDir = self::basePath() . '/' . $rel;
        mkdir($this->uploadDir, 0700, true);
        $datei = $this->uploadDir . '/bewerbung.bin';
        file_put_contents($datei, 'verschlüsselt (fiktiv)');
        $this->db()->prepare("INSERT INTO job_applications (uuid, name, email, datei_pfad, status, created_at, updated_at) VALUES (?, 'Fiktive Person', 'bewerbung@example.org', ?, 'abgeschlossen', ?, ?)")
            ->execute([Uuid::v4(), $rel . '/bewerbung.bin', $alt, $alt]);
        $this->db()->prepare("INSERT INTO job_applications (uuid, name, email, datei_pfad, status, created_at, updated_at) VALUES (?, 'Fiktive Person', 'bewerbung@example.org', ?, 'abgeschlossen', ?, ?)")
            ->execute([Uuid::v4(), '../ausserhalb.bin', $alt, $alt]);

        return ['spamAlt' => $spamAlt, 'verlorenAlt' => $verlorenAlt, 'verlorenNeu' => $verlorenNeu, 'neuAlt' => $neuAlt, 'datei' => $datei];
    }

    public function testDisabledWithoutRetentionDays(): void
    {
        $retention = $this->retention(null);
        self::assertNull($retention->configuredDays());
        $result = $retention->run(null, false);
        self::assertFalse($result['aktiv']);
        self::assertSame([], $result['ergebnisse']);
    }

    public function testDryRunCountsWithoutChanges(): void
    {
        Clock::freeze('2026-09-23 12:00:00');
        $ids = $this->seed();
        $before = [$this->countRows('leads'), $this->countRows('contact_requests'), $this->countRows('job_applications'), $this->countRows('outbox', 'payload IS NOT NULL')];

        $result = $this->retention()->run(90, true);

        self::assertTrue($result['aktiv']);
        self::assertTrue($result['dry_run']);
        self::assertSame('2026-06-25 12:00:00', $result['stichtag']);
        self::assertSame(1, $result['ergebnisse']['leads_spam_geloescht']);
        self::assertSame(1, $result['ergebnisse']['leads_verloren_anonymisiert']);
        self::assertSame(1, $result['ergebnisse']['kontaktanfragen_geloescht']);
        self::assertSame(1, $result['ergebnisse']['bewerbungen_geloescht']);
        self::assertSame(1, $result['ergebnisse']['dateien_geloescht']);
        self::assertSame(1, $result['ergebnisse']['dateien_fehler']);
        self::assertSame(1, $result['ergebnisse']['outbox_bereinigt']);

        self::assertSame($before, [$this->countRows('leads'), $this->countRows('contact_requests'), $this->countRows('job_applications'), $this->countRows('outbox', 'payload IS NOT NULL')]);
        self::assertFileExists($ids['datei']);
        self::assertSame('erika.beispiel@example.org', $this->row('SELECT contact_email FROM leads WHERE id = ?', [$ids['verlorenAlt']])['contact_email']);
    }

    public function testRunDeletesAndAnonymises(): void
    {
        Clock::freeze('2026-09-23 12:00:00');
        $ids = $this->seed();
        $result = $this->retention()->run(90, false);

        self::assertSame(0, $this->countRows('leads', 'id = ?', [$ids['spamAlt']]));
        self::assertSame(0, $this->countRows('outbox', 'lead_id = ?', [$ids['spamAlt']]));

        $lead = $this->row('SELECT * FROM leads WHERE id = ?', [$ids['verlorenAlt']]);
        foreach (Retention::ANONYMIZE_NULL as $column) {
            self::assertNull($lead[$column], $column);
        }
        self::assertSame('41', $lead['object_zip']);
        self::assertSame('weg', $lead['management_form']);
        self::assertSame(10, (int) $lead['units_residential']);
        self::assertSame('Musterregion', $lead['region']);
        self::assertSame('2026-09-23 12:00:00', $lead['anonymized_at']);
        self::assertSame(0, $this->countRows('lead_events', 'lead_id = ? AND notiz IS NOT NULL', [$ids['verlorenAlt']]));
        self::assertSame(1, $this->countRows('lead_events', "lead_id = ? AND typ = 'anonymisiert'", [$ids['verlorenAlt']]));
        self::assertSame(0, $this->countRows('outbox', 'lead_id = ?', [$ids['verlorenAlt']]));

        // Kürzlich verloren und offene Leads bleiben unverändert
        self::assertNull($this->row('SELECT anonymized_at FROM leads WHERE id = ?', [$ids['verlorenNeu']])['anonymized_at']);
        self::assertSame('erika.beispiel@example.org', $this->row('SELECT contact_email FROM leads WHERE id = ?', [$ids['neuAlt']])['contact_email']);

        self::assertSame(1, $this->countRows('contact_requests'));
        self::assertSame(1, $this->countRows('job_applications'));
        self::assertSame('../ausserhalb.bin', $this->row('SELECT datei_pfad FROM job_applications')['datei_pfad']);
        self::assertFileDoesNotExist($ids['datei']);
        self::assertSame(0, $this->countRows('outbox', 'payload IS NOT NULL AND status = ?', ['failed']));
        self::assertSame(1, $result['ergebnisse']['dateien_fehler']);

        // Zweiter Lauf findet nichts mehr
        $again = $this->retention()->run(90, false);
        self::assertSame(0, $again['ergebnisse']['leads_spam_geloescht']);
        self::assertSame(0, $again['ergebnisse']['leads_verloren_anonymisiert']);
    }

    public function testCliDryRunAndDisabledHint(): void
    {
        [$code, $output] = self::runPhp('bin/retention.php', ['--dry-run'], ['LEAD_RETENTION_DAYS' => '', 'APP_KEY' => self::APP_KEY]);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Löschlauf deaktiviert', $output);

        $this->insertLead(['status' => 'spam', 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00']);
        [$code, $output] = self::runPhp('bin/retention.php', ['--dry-run'], ['LEAD_RETENTION_DAYS' => '30', 'APP_KEY' => self::APP_KEY]);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Trockenlauf', $output);
        self::assertMatchesRegularExpression('/leads_spam_geloescht\s+1/', $output);
        self::assertStringNotContainsString('example.org', $output);
        self::assertSame(1, $this->countRows('leads'));
    }
}
