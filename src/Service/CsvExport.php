<?php

declare(strict_types=1);

namespace Hvm\Service;

use DateTimeImmutable;
use DateTimeZone;
use Hvm\Validation\AngebotValidator;

/**
 * CSV-Export für Excel und LibreOffice: UTF-8 mit BOM, Semikolon als Trennzeichen, CRLF.
 *
 * Schutz gegen CSV-Formelinjektion: Zellen, die mit =, +, -, @, Tabulator oder Wagenrücklauf beginnen,
 * erhalten ein vorangestelltes Hochkomma. Tabellenprogramme werten sie dann als Text.
 */
final class CsvExport
{
    public const DELIMITER = ';';
    public const BOM = "\xEF\xBB\xBF";

    private const FORMULA_START = ['=', '+', '-', '@', "\t", "\r"];

    /** Spalten des Lead-Exports: Schlüssel in leads => Überschrift */
    public const LEAD_COLUMNS = [
        'uuid' => 'UUID',
        'created_at' => 'Eingang',
        'status' => 'Status',
        'management_form' => 'Verwaltungsart',
        'contact_salutation' => 'Anrede',
        'contact_first_name' => 'Vorname',
        'contact_last_name' => 'Nachname',
        'contact_email' => 'E-Mail',
        'contact_phone' => 'Telefon',
        'contact_role' => 'Rolle',
        'contact_street' => 'Kontakt Straße',
        'contact_zip' => 'Kontakt PLZ',
        'contact_city' => 'Kontakt Ort',
        'object_street' => 'Objekt Straße',
        'object_zip' => 'Objekt PLZ',
        'object_city' => 'Objekt Ort',
        'year_of_construction' => 'Baujahr',
        'units_residential' => 'Wohneinheiten',
        'units_commercial' => 'Gewerbeeinheiten',
        'units_parking' => 'Stellplätze',
        'management_start' => 'Verwaltungsbeginn',
        'has_current_manager' => 'Aktueller Verwalter',
        'message' => 'Nachricht',
        'region' => 'Region',
        'source' => 'Quelle',
        'utm_source' => 'utm_source',
        'utm_medium' => 'utm_medium',
        'utm_campaign' => 'utm_campaign',
        'utm_term' => 'utm_term',
        'utm_content' => 'utm_content',
        'landing_page' => 'Einstiegsseite',
        'referrer' => 'Referrer',
        'assigned_to_email' => 'Zugewiesen an',
        'notes' => 'Notiz',
        'legacy_id' => 'Alt-ID',
        'anonymized_at' => 'Anonymisiert am',
    ];

    /**
     * Neutralisiert einen Zellwert gegen Formelinjektion.
     */
    public static function sanitize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $text = str_replace("\0", '', (string) $value);
        if ($text !== '' && in_array($text[0], self::FORMULA_START, true)) {
            return "'" . $text;
        }

        return $text;
    }

    /**
     * @param list<string>                    $header
     * @param iterable<list<mixed>>           $rows
     */
    public static function build(array $header, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Temporärer Speicher für CSV nicht verfügbar.');
        }
        fputcsv($handle, array_map(self::sanitize(...), $header), self::DELIMITER, '"', '', "\r\n");
        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::sanitize(...), $row), self::DELIMITER, '"', '', "\r\n");
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return self::BOM . $csv;
    }

    /**
     * Lead-Export mit lesbaren Werten (Datum TT.MM.JJJJ in deutscher Zeit, Bezeichnungen statt Schlüsseln).
     *
     * @param iterable<array<string, mixed>> $leads
     */
    public static function leads(iterable $leads): string
    {
        $rows = (static function () use ($leads): \Generator {
            foreach ($leads as $lead) {
                $row = [];
                foreach (array_keys(self::LEAD_COLUMNS) as $key) {
                    $row[] = self::formatLeadValue($key, $lead[$key] ?? null);
                }
                yield $row;
            }
        })();

        return self::build(array_values(self::LEAD_COLUMNS), $rows);
    }

    private static function formatLeadValue(string $key, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($key) {
            'created_at', 'anonymized_at' => self::localDateTime((string) $value),
            'management_start' => self::date((string) $value),
            'management_form' => AngebotValidator::ARTEN[(string) $value] ?? $value,
            'contact_salutation' => AngebotValidator::ANREDEN[(string) $value] ?? $value,
            'contact_role' => AngebotValidator::ROLLEN[(string) $value] ?? $value,
            'status' => LeadAdminService::STATUS_LABELS[(string) $value] ?? $value,
            'has_current_manager' => (int) $value === 1 ? 'ja' : 'nein',
            default => $value,
        };
    }

    private static function localDateTime(string $utc): string
    {
        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i');
        } catch (\Exception) {
            return $utc;
        }
    }

    private static function date(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));

        return $parsed === false ? $date : $parsed->format('d.m.Y');
    }
}
