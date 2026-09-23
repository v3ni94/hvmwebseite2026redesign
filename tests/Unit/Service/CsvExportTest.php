<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Service;

use Hvm\Service\CsvExport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvExportTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function cells(): iterable
    {
        yield 'Gleichheitszeichen' => ['=HYPERLINK("http://example.org")', "'=HYPERLINK(\"http://example.org\")"];
        yield 'Plus' => ['+49 211', "'+49 211"];
        yield 'Minus' => ['-2+3', "'-2+3"];
        yield 'At' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'Tabulator' => ["\t=1", "'\t=1"];
        yield 'Wagenrücklauf' => ["\r=1", "'\r=1"];
        yield 'normaler Text' => ['Musterstadt', 'Musterstadt'];
        yield 'Zeichen in der Mitte' => ['a=b', 'a=b'];
        yield 'Zahl' => [12, '12'];
        yield 'null' => [null, ''];
        yield 'Nullbyte' => ["ab\0c", 'abc'];
    }

    #[DataProvider('cells')]
    public function testSanitize(mixed $input, string $expected): void
    {
        self::assertSame($expected, CsvExport::sanitize($input));
    }

    public function testBuildUsesBomSemicolonCrlfAndQuoting(): void
    {
        $csv = CsvExport::build(['Name', 'Notiz'], [['Muster; Erika', "Zeile 1\nZeile 2"], ['=1+1', 'Er sagte "ja"']]);
        self::assertStringStartsWith("\xEF\xBB\xBF" . "Name;Notiz\r\n", $csv);
        self::assertStringContainsString("\"Muster; Erika\";\"Zeile 1\nZeile 2\"\r\n", $csv);
        self::assertStringContainsString("'=1+1;\"Er sagte \"\"ja\"\"\"\r\n", $csv);
    }

    public function testLeadExportFormatsValuesAndNeutralisesFormulas(): void
    {
        $csv = CsvExport::leads([[
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'created_at' => '2026-09-01 08:00:00',
            'status' => 'kontaktiert',
            'management_form' => 'weg',
            'contact_first_name' => '=cmd|\' /C calc\'!A0',
            'contact_last_name' => 'Beispiel',
            'contact_email' => 'erika@example.org',
            'management_start' => '2027-01-01',
            'has_current_manager' => 1,
            'units_residential' => 12,
        ]]);
        $lines = explode("\r\n", substr($csv, 3));
        $header = str_getcsv($lines[0], ';', '"', '');
        $row = array_combine($header, str_getcsv($lines[1], ';', '"', ''));
        self::assertSame('01.09.2026 10:00', $row['Eingang']);
        self::assertSame('Kontaktiert', $row['Status']);
        self::assertSame('WEG-Verwaltung', $row['Verwaltungsart']);
        self::assertSame("'=cmd|' /C calc'!A0", $row['Vorname']);
        self::assertSame('01.01.2027', $row['Verwaltungsbeginn']);
        self::assertSame('ja', $row['Aktueller Verwalter']);
        self::assertSame('12', $row['Wohneinheiten']);
        self::assertSame(count(CsvExport::LEAD_COLUMNS), count($header));
    }
}
