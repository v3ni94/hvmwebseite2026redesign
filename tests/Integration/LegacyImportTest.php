<?php

declare(strict_types=1);

namespace Hvm\Tests\Integration;

use Hvm\Service\LegacyImport;
use Hvm\Support\Clock;

/**
 * Import des Altbestands: Zuordnung, Idempotenz, Schutz anonymisierter Leads, Protokoll ohne personenbezogene Daten.
 */
final class LegacyImportTest extends AdminTestCase
{
    private const MAPPING = [
        'managementform' => [1 => 'weg', 2 => 'miet'],
        'preisstufen' => ['private' => [], 'commercial' => [], 'parking' => []],
        'anrede' => ['frau' => 'frau', 'herr' => 'herr'],
        'status' => 'neu',
        'quelle_ohne_angabe' => 'altbestand',
        'zeitzone' => 'Europe/Berlin',
    ];

    /** @var list<string> */
    private array $protocols = [];

    protected function tearDown(): void
    {
        foreach ($this->protocols as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private static function fixture(string $name): string
    {
        return self::basePath() . '/tests/fixtures/legacy/' . $name;
    }

    private function import(array $mapping = self::MAPPING): LegacyImport
    {
        return new LegacyImport($this->db(), $mapping);
    }

    public function testImportMapsRowsAndReportsErrors(): void
    {
        Clock::freeze('2026-09-23 12:00:00');
        $this->db()->exec("INSERT INTO price_tiers (id, management_form, unit_type, units_from, units_to, price_net_per_unit_month, valid_from, legacy_id) VALUES (501, 'weg', 'residential', 1, NULL, 0.00, '2024-01-01', 7)");
        $result = $this->import()->import(LegacyImport::readFile(self::fixture('properties.csv')), false);

        self::assertSame(['gelesen' => 4, 'neu' => 3, 'aktualisiert' => 0, 'unveraendert' => 0, 'uebersprungen' => 0, 'fehler' => 1, 'hinweise' => 2], $result['zaehler']);
        self::assertContains(['legacy_id' => 102, 'aktion' => 'fehler', 'grund' => 'managementform_id ohne Zuordnung'], $result['eintraege']);

        $lead = $this->row('SELECT * FROM leads WHERE legacy_id = 101');
        self::assertSame('weg', $lead['management_form']);
        self::assertSame('frau', $lead['contact_salutation']);
        self::assertSame('Musterstadt', $lead['object_city']);
        self::assertSame(501, (int) $lead['price_tier_residential_id']);
        self::assertSame('2023-11-14 08:30:00', $lead['created_at']);
        self::assertSame('neu', $lead['status']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $lead['uuid']);
        self::assertSame(1, $this->countRows('lead_events', "lead_id = ? AND typ = 'import'", [$lead['id']]));

        $lead103 = $this->row('SELECT * FROM leads WHERE legacy_id = 103');
        self::assertSame('miet', $lead103['management_form']);
        self::assertNull($lead103['contact_email']);
        self::assertNull($lead103['price_tier_commercial_id']);
        self::assertSame('2024-02-01 07:00:00', $lead103['created_at']);
    }

    public function testSecondImportIsIdempotentAndUpdatesOnlyChangedRows(): void
    {
        Clock::freeze('2026-09-23 12:00:00');
        $rows = LegacyImport::readFile(self::fixture('properties.csv'));
        $this->import()->import($rows, false);
        $leads = $this->countRows('leads');
        $events = $this->countRows('lead_events');

        // Status aus der neuen Anwendung bleibt beim erneuten Import erhalten
        $this->db()->exec("UPDATE leads SET status = 'kontaktiert' WHERE legacy_id = 101");

        $second = $this->import()->import($rows, false);
        self::assertSame(0, $second['zaehler']['neu']);
        self::assertSame(3, $second['zaehler']['unveraendert']);
        self::assertSame(0, $second['zaehler']['aktualisiert']);
        self::assertSame($leads, $this->countRows('leads'));
        self::assertSame($events, $this->countRows('lead_events'));

        $rows[0]['City'] = 'Neustadt';
        $third = $this->import()->import($rows, false);
        self::assertSame(1, $third['zaehler']['aktualisiert']);
        $lead = $this->row('SELECT object_city, status FROM leads WHERE legacy_id = 101');
        self::assertSame(['Neustadt', 'kontaktiert'], [$lead['object_city'], $lead['status']]);
        self::assertSame($leads, $this->countRows('leads'));
    }

    public function testMissingCreatedAtStaysStableAcrossRuns(): void
    {
        $rows = [['ID' => '104', 'managementform_id' => '1']];
        Clock::freeze('2026-09-23 12:00:00');
        $this->import()->import($rows, false);
        Clock::freeze('2026-09-24 08:00:00');
        $second = $this->import()->import($rows, false);
        self::assertSame(1, $second['zaehler']['unveraendert']);
        self::assertSame('2026-09-23 12:00:00', $this->row('SELECT created_at FROM leads WHERE legacy_id = 104')['created_at']);
    }

    public function testDryRunWritesNothing(): void
    {
        $result = $this->import()->import(LegacyImport::readFile(self::fixture('properties.sql')), true);
        self::assertSame(3, $result['zaehler']['neu']);
        self::assertSame(0, $this->countRows('leads'));
    }

    public function testAnonymisedLeadsAreNotRefilled(): void
    {
        $rows = LegacyImport::readFile(self::fixture('properties.sql'));
        $this->import()->import($rows, false);
        $this->db()->exec("UPDATE leads SET contact_first_name = NULL, contact_last_name = NULL, contact_email = NULL, anonymized_at = '2026-09-01 00:00:00' WHERE legacy_id = 201");
        $result = $this->import()->import($rows, false);
        self::assertSame(1, $result['zaehler']['uebersprungen']);
        self::assertNull($this->row('SELECT contact_email FROM leads WHERE legacy_id = 201')['contact_email']);
    }

    public function testDuplicateIdsInFileAreReported(): void
    {
        $rows = LegacyImport::readFile(self::fixture('properties.sql'));
        $rows[] = $rows[0];
        $result = $this->import()->import($rows, true);
        self::assertContains(['legacy_id' => 201, 'aktion' => 'fehler', 'grund' => 'ID mehrfach in der Datei'], $result['eintraege']);
    }

    public function testCliWritesProtocolWithoutPersonalData(): void
    {
        $before = glob(self::basePath() . '/storage/logs/import-*.log') ?: [];
        // config/legacy-mapping.php enthält noch keine Zuordnung: alle Zeilen scheitern mit Grund
        [$code, $output] = self::runPhp('bin/import-legacy-leads.php', [self::fixture('properties.csv'), '--dry-run'], ['APP_KEY' => self::APP_KEY]);
        $after = glob(self::basePath() . '/storage/logs/import-*.log') ?: [];
        $this->protocols = array_values(array_diff($after, $before));

        self::assertSame(3, $code, $output);
        self::assertStringContainsString('Trockenlauf', $output);
        self::assertCount(1, $this->protocols);
        $protocol = (string) file_get_contents($this->protocols[0]);
        self::assertStringContainsString('legacy_id=101 aktion=fehler grund=managementform_id ohne Zuordnung', $protocol);
        self::assertStringContainsString('fehler: 4', $protocol);
        foreach (['Erika', 'Beispiel', 'example.org', 'Musterweg', '0211'] as $pii) {
            self::assertStringNotContainsString($pii, $protocol);
            self::assertStringNotContainsString($pii, $output);
        }
        self::assertSame(0, $this->countRows('leads'));
    }
}
