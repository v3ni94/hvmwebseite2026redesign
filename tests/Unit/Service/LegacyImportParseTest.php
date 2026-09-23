<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Service;

use Hvm\Service\LegacyImport;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Einlesen und Zuordnung ohne Datenbank (Preisstufen werden hier nicht nachgeschlagen).
 */
final class LegacyImportParseTest extends TestCase
{
    private static function fixture(string $name): string
    {
        return dirname(__DIR__, 2) . '/fixtures/legacy/' . $name;
    }

    private function import(): LegacyImport
    {
        return new LegacyImport($this->createStub(PDO::class), [
            'managementform' => [1 => 'weg', 2 => 'miet'],
            'anrede' => ['frau' => 'frau', 'herr' => 'herr'],
            'status' => 'neu',
            'quelle_ohne_angabe' => 'altbestand',
            'zeitzone' => 'Europe/Berlin',
        ]);
    }

    public function testCsvIsReadWithCanonicalFieldNames(): void
    {
        $rows = LegacyImport::readFile(self::fixture('properties.csv'));
        self::assertCount(4, $rows);
        self::assertSame('101', $rows[0]['ID']);
        self::assertSame('Erika', $rows[0]['Contact First Name']);
        self::assertNull($rows[0]['Contact Street']);
        self::assertSame('9', $rows[1]['managementform_id']);
    }

    public function testCsvWithWindows1252AndCommaDelimiter(): void
    {
        $csv = mb_convert_encoding("id,contact_first_name,City,managementform_id\n7,Jürgen,Köln,1\n", 'Windows-1252', 'UTF-8');
        $rows = LegacyImport::parseCsv($csv);
        self::assertSame('Jürgen', $rows[0]['Contact First Name']);
        self::assertSame('Köln', $rows[0]['City']);
    }

    public function testCsvWithoutRequiredColumnIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Pflichtspalte fehlt: managementform_id');
        LegacyImport::parseCsv("ID;City\n1;Musterstadt\n");
    }

    public function testSqlDumpWithAndWithoutColumnList(): void
    {
        $rows = LegacyImport::readFile(self::fixture('properties.sql'));
        self::assertCount(3, $rows);
        self::assertSame("O'Beispiel", $rows[0]['Contact Last Name']);
        self::assertSame('Weg; mit Semikolon 3', $rows[0]['Street']);
        self::assertNull($rows[0]['Contact Telephone']);
        self::assertSame('Straße (Hof) 4', $rows[1]['Street']);
        self::assertSame('203', $rows[2]['ID']);
        self::assertSame("It's Musterort", $rows[2]['City']);
        self::assertArrayNotHasKey('Contact Email', $rows[2]);
    }

    public function testMapRowConvertsValues(): void
    {
        $rows = LegacyImport::readFile(self::fixture('properties.csv'));
        $mapped = $this->import()->mapRow($rows[0], new \DateTimeImmutable('2026-09-23 12:00:00', new \DateTimeZone('UTC')));
        self::assertSame(101, $mapped['legacy_id']);
        self::assertSame('weg', $mapped['data']['management_form']);
        self::assertSame('frau', $mapped['data']['contact_salutation']);
        self::assertSame('erika.beispiel@example.org', $mapped['data']['contact_email']);
        self::assertSame('2024-03-01', $mapped['data']['management_start']);
        self::assertSame(1985, $mapped['data']['year_of_construction']);
        self::assertSame('altbestand', $mapped['data']['source']);
        // 09:30 Uhr deutscher Winterzeit = 08:30 UTC
        self::assertSame('2023-11-14 08:30:00', $mapped['data']['created_at']);
        self::assertSame([], $mapped['hinweise'] === [] ? [] : array_filter($mapped['hinweise'], static fn (string $h): bool => !str_starts_with($h, 'Preisstufe')));
    }

    public function testMapRowCollectsHintsWithoutPersonalData(): void
    {
        $rows = LegacyImport::readFile(self::fixture('properties.csv'));
        $mapped = $this->import()->mapRow($rows[2]);
        self::assertSame('miet', $mapped['data']['management_form']);
        self::assertNull($mapped['data']['contact_email']);
        self::assertNull($mapped['data']['contact_salutation']);
        self::assertNull($mapped['data']['year_of_construction']);
        self::assertContains('E-Mail ungültig', $mapped['hinweise']);
        self::assertContains('Anrede ohne Zuordnung', $mapped['hinweise']);
        self::assertContains('Baujahr ungültig', $mapped['hinweise']);
        foreach ($mapped['hinweise'] as $hinweis) {
            self::assertStringNotContainsString('Lena', $hinweis);
            self::assertStringNotContainsString('keine-adresse', $hinweis);
        }
    }

    public function testUnmappedManagementFormIsAnError(): void
    {
        $rows = LegacyImport::readFile(self::fixture('properties.csv'));
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('managementform_id ohne Zuordnung');
        $this->import()->mapRow($rows[1]);
    }

    public function testMonthParsing(): void
    {
        self::assertSame('2024-03-01', LegacyImport::parseMonth('03.2024'));
        self::assertSame('2024-03-01', LegacyImport::parseMonth('2024-03-15'));
        self::assertSame('2024-03-01', LegacyImport::parseMonth('2024-03'));
        self::assertNull(LegacyImport::parseMonth('März'));
        self::assertNull(LegacyImport::parseMonth('2024-13-01'));
    }
}
