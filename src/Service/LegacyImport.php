<?php

declare(strict_types=1);

namespace Hvm\Service;

use DateTimeImmutable;
use DateTimeZone;
use Hvm\Repository\LeadRepository;
use Hvm\Support\Clock;
use Hvm\Support\Config;
use Hvm\Support\Uuid;
use PDO;

/**
 * Import der Alttabelle "Properties" (MP 6.3, Bestandsaufnahme Abschnitt 4) in leads.
 *
 * - Eingabe: CSV-Export (Trennzeichen ; , oder Tabulator, UTF-8 oder Windows-1252) oder SQL-Dump
 *   (INSERT INTO `Properties` ..., mit oder ohne Spaltenliste).
 * - Zuordnung der Verwaltungsform, Preisstufen und Anrede über config/legacy-mapping.php.
 * - Idempotent über leads.legacy_id: unveränderte Zeilen bleiben unberührt, geänderte Werte werden
 *   aktualisiert (Status, Zuweisung und Notizen der neuen Anwendung bleiben erhalten), anonymisierte
 *   Leads werden nie erneut befüllt.
 * - Ergebnis und Protokoll enthalten nur Zählwerte, legacy_id und Gründe, keine personenbezogenen Daten.
 */
final class LegacyImport
{
    public const FIELDS = [
        'ID', 'Contact Gender', 'Contact First Name', 'Contact Last Name', 'Contact Telephone', 'Contact Email',
        'Contact Street', 'Contact Zip', 'Contact City', 'management_start', 'Year of Construction',
        'Street', 'Zip', 'City', 'Source', 'Created At',
        'managementform_id', 'private_managementcost_id', 'commercial_managementcost_id', 'parking_managementcost_id',
    ];

    public const REQUIRED = ['ID', 'managementform_id'];

    /** Spalten, die ein erneuter Import aktualisieren darf */
    public const IMPORT_COLUMNS = [
        'management_form', 'contact_salutation', 'contact_first_name', 'contact_last_name', 'contact_email', 'contact_phone',
        'contact_street', 'contact_zip', 'contact_city', 'object_street', 'object_zip', 'object_city', 'year_of_construction',
        'management_start', 'source', 'created_at',
        'price_tier_residential_id', 'price_tier_commercial_id', 'price_tier_parking_id',
    ];

    private const TIER_TYPES = [
        'private' => ['field' => 'private_managementcost_id', 'column' => 'price_tier_residential_id', 'unit' => 'residential'],
        'commercial' => ['field' => 'commercial_managementcost_id', 'column' => 'price_tier_commercial_id', 'unit' => 'commercial'],
        'parking' => ['field' => 'parking_managementcost_id', 'column' => 'price_tier_parking_id', 'unit' => 'parking'],
    ];

    /** @var array<string, ?int> */
    private array $tierCache = [];

    /**
     * @param array<string, mixed> $mapping Inhalt von config/legacy-mapping.php
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $mapping,
    ) {
    }

    public static function fromConfig(PDO $pdo, Config $config): self
    {
        return new self($pdo, $config->array('legacy-mapping'));
    }

    public static function normalizeKey(string $key): string
    {
        return trim((string) preg_replace('/[\s_\-]+/', ' ', mb_strtolower(trim($key, " \t\n\r\0\x0B`\"'"))));
    }

    /**
     * Liest eine CSV- oder SQL-Datei (Erkennung über Endung und Inhalt).
     *
     * @return list<array<string, ?string>>
     */
    public static function readFile(string $path, string $table = 'Properties'): array
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \InvalidArgumentException('Datei kann nicht gelesen werden.');
        }
        $isSql = str_ends_with(strtolower($path), '.sql') || preg_match('/^\s*(--|\/\*|CREATE\s+TABLE|INSERT\s+INTO|SET\s)/i', ltrim($content, "\xEF\xBB\xBF")) === 1;

        return $isSql ? self::parseSqlDump($content, $table) : self::parseCsv($content);
    }

    /**
     * @return list<array<string, ?string>> Zeilen mit kanonischen Feldnamen (FIELDS)
     */
    public static function parseCsv(string $content): array
    {
        $content = self::toUtf8($content);
        $firstLine = strtok($content, "\r\n") ?: '';
        $counts = [';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Temporärer Speicher nicht verfügbar.');
        }
        fwrite($handle, $content);
        rewind($handle);

        $header = fgetcsv($handle, null, $delimiter, '"', '');
        if (!is_array($header)) {
            fclose($handle);
            throw new \InvalidArgumentException('Die CSV-Datei enthält keine Kopfzeile.');
        }
        $columns = self::canonicalColumns(array_map(static fn ($h): string => (string) $h, $header));

        $rows = [];
        while (($record = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            if ($record === [null] || $record === ['']) {
                continue;
            }
            $row = [];
            foreach ($columns as $index => $field) {
                if ($field !== null) {
                    $row[$field] = self::nullable($record[$index] ?? null);
                }
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return list<array<string, ?string>>
     */
    public static function parseSqlDump(string $content, string $table = 'Properties'): array
    {
        $content = self::toUtf8($content);
        $quoted = preg_quote($table, '/');
        $tableName = '(?:`?[\w$]+`?\.)?`?' . $quoted . '`?';

        $createColumns = [];
        if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . $tableName . '\s*\((.*?)\)\s*(?:ENGINE|DEFAULT\s+CHARSET|CHARSET|COMMENT\s*=|;)/is', $content, $m) === 1) {
            preg_match_all('/^\s*`([^`]+)`\s+/m', $m[1], $cm);
            $createColumns = $cm[1];
        }

        $rows = [];
        $pattern = '/INSERT\s+(?:IGNORE\s+)?INTO\s+' . $tableName . '\s*(?:\(([^)]*)\))?\s*VALUES\s*/i';
        $offset = 0;
        while (preg_match($pattern, $content, $im, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $names = isset($im[1]) && $im[1][0] !== ''
                ? array_map(static fn (string $c): string => trim($c, " \t\n\r`\"'"), explode(',', $im[1][0]))
                : $createColumns;
            if ($names === []) {
                throw new \InvalidArgumentException('INSERT ohne Spaltenliste und ohne CREATE TABLE im Dump.');
            }
            $columns = self::canonicalColumns($names);
            $position = $im[0][1] + strlen($im[0][0]);
            [$tuples, $position] = self::parseTuples($content, $position);
            foreach ($tuples as $values) {
                $row = [];
                foreach ($columns as $index => $field) {
                    if ($field !== null) {
                        $row[$field] = $values[$index] ?? null;
                    }
                }
                $rows[] = $row;
            }
            $offset = $position;
        }

        return $rows;
    }

    /**
     * Zerlegt "(...),(...);" ab einer Position in Wertelisten.
     *
     * @return array{0: list<list<?string>>, 1: int}
     */
    private static function parseTuples(string $sql, int $i): array
    {
        $length = strlen($sql);
        $tuples = [];
        while ($i < $length) {
            while ($i < $length && ctype_space($sql[$i])) {
                $i++;
            }
            if ($i >= $length || $sql[$i] === ';') {
                return [$tuples, $i + 1];
            }
            if ($sql[$i] === ',') {
                $i++;
                continue;
            }
            if ($sql[$i] !== '(') {
                throw new \InvalidArgumentException('Unerwartetes Zeichen im SQL-Dump.');
            }
            $i++;
            $values = [];
            while ($i < $length) {
                while ($i < $length && ctype_space($sql[$i])) {
                    $i++;
                }
                $char = $sql[$i] ?? '';
                if ($char === "'" || $char === '"') {
                    $value = '';
                    $i++;
                    while ($i < $length) {
                        $c = $sql[$i];
                        if ($c === '\\' && $i + 1 < $length) {
                            $next = $sql[$i + 1];
                            $value .= match ($next) {
                                'n' => "\n", 'r' => "\r", 't' => "\t", '0' => "\0", 'Z' => "\x1A", default => $next,
                            };
                            $i += 2;
                            continue;
                        }
                        if ($c === $char) {
                            if (($sql[$i + 1] ?? '') === $char) {
                                $value .= $char;
                                $i += 2;
                                continue;
                            }
                            $i++;
                            break;
                        }
                        $value .= $c;
                        $i++;
                    }
                    $values[] = $value;
                } else {
                    $start = $i;
                    while ($i < $length && $sql[$i] !== ',' && $sql[$i] !== ')') {
                        $i++;
                    }
                    $token = trim(substr($sql, $start, $i - $start));
                    $values[] = strtoupper($token) === 'NULL' ? null : $token;
                }
                while ($i < $length && ctype_space($sql[$i])) {
                    $i++;
                }
                if (($sql[$i] ?? '') === ',') {
                    $i++;
                    continue;
                }
                if (($sql[$i] ?? '') === ')') {
                    $i++;
                    break;
                }
                throw new \InvalidArgumentException('Unvollständige Werteliste im SQL-Dump.');
            }
            $tuples[] = $values;
        }

        return [$tuples, $i];
    }

    /**
     * @param list<string> $names
     * @return array<int, ?string> Spaltenindex => kanonischer Feldname oder null (unbekannt)
     */
    private static function canonicalColumns(array $names): array
    {
        $known = [];
        foreach (self::FIELDS as $field) {
            $known[self::normalizeKey($field)] = $field;
        }
        $columns = [];
        foreach ($names as $index => $name) {
            $name = ltrim($name, "\xEF\xBB\xBF");
            $columns[$index] = $known[self::normalizeKey($name)] ?? null;
        }
        foreach (self::REQUIRED as $required) {
            if (!in_array($required, $columns, true)) {
                throw new \InvalidArgumentException('Pflichtspalte fehlt: ' . $required);
            }
        }

        return $columns;
    }

    private static function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        return $content;
    }

    private static function nullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' || $value === '\\N' || strtoupper($value) === 'NULL' ? null : $value;
    }

    /**
     * Wandelt eine Zeile der Alttabelle in Spalten für leads.
     *
     * @param array<string, ?string> $row
     * @return array{legacy_id: int, data: array<string, mixed>, hinweise: list<string>, eingang_ersetzt: bool}
     *         eingang_ersetzt: Created At fehlte, created_at ist der Importzeitpunkt und wird bei erneutem Import nicht überschrieben
     * @throws \DomainException mit einem Grund ohne personenbezogene Daten
     */
    public function mapRow(array $row, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $value = static fn (string $field): ?string => self::nullable($row[$field] ?? null);
        $hinweise = [];

        $id = $value('ID');
        if ($id === null || !ctype_digit($id) || (int) $id <= 0 || (int) $id > 4294967295) {
            throw new \DomainException('ID fehlt oder ist ungültig');
        }
        $legacyId = (int) $id;

        $formId = $value('managementform_id');
        $form = $formId === null ? null : $this->lookup('managementform', $formId);
        if (!in_array($form, ['weg', 'miet', 'se'], true)) {
            throw new \DomainException($formId === null ? 'managementform_id fehlt' : 'managementform_id ohne Zuordnung');
        }

        $salutation = null;
        $gender = $value('Contact Gender');
        if ($gender !== null) {
            $salutation = $this->lookup('anrede', mb_strtolower($gender));
            if (!in_array($salutation, ['frau', 'herr', 'keine'], true)) {
                $salutation = null;
                $hinweise[] = 'Anrede ohne Zuordnung';
            }
        }

        $email = $value('Contact Email');
        if ($email !== null && (mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $email = null;
            $hinweise[] = 'E-Mail ungültig';
        }

        $year = $value('Year of Construction');
        $yearInt = null;
        if ($year !== null) {
            if (ctype_digit($year) && (int) $year >= 1000 && (int) $year <= (int) $now->format('Y') + 10) {
                $yearInt = (int) $year;
            } elseif ($year !== '0') {
                $hinweise[] = 'Baujahr ungültig';
            }
        }

        $start = $value('management_start');
        $startDate = null;
        if ($start !== null && !str_starts_with($start, '0000')) {
            $startDate = self::parseMonth($start);
            if ($startDate === null) {
                $hinweise[] = 'management_start nicht lesbar';
            }
        }

        $zone = new DateTimeZone(is_string($this->mapping['zeitzone'] ?? null) ? $this->mapping['zeitzone'] : 'Europe/Berlin');
        $createdRaw = $value('Created At');
        $created = $createdRaw === null ? null : self::parseDateTime($createdRaw, $zone);
        $createdFallback = $created === null;
        if ($created === null) {
            $hinweise[] = 'Created At fehlt oder ist nicht lesbar, Importzeitpunkt verwendet';
            $created = $now;
        }

        $source = $value('Source');
        $source = $source === null
            ? (is_string($this->mapping['quelle_ohne_angabe'] ?? null) && $this->mapping['quelle_ohne_angabe'] !== '' ? $this->mapping['quelle_ohne_angabe'] : null)
            : mb_substr($source, 0, 50);

        $data = [
            'management_form' => $form,
            'contact_salutation' => $salutation,
            'contact_first_name' => self::cut($value('Contact First Name'), 100),
            'contact_last_name' => self::cut($value('Contact Last Name'), 100),
            'contact_email' => $email,
            'contact_phone' => self::cut($value('Contact Telephone'), 40),
            'contact_street' => self::cut($value('Contact Street'), 150),
            'contact_zip' => self::cut($value('Contact Zip'), 10),
            'contact_city' => self::cut($value('Contact City'), 100),
            'object_street' => self::cut($value('Street'), 150),
            'object_zip' => self::cut($value('Zip'), 10),
            'object_city' => self::cut($value('City'), 100),
            'year_of_construction' => $yearInt,
            'management_start' => $startDate,
            'source' => $source,
            'created_at' => $created->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];

        foreach (self::TIER_TYPES as $type => $spec) {
            $legacyTier = $value($spec['field']);
            $tier = null;
            if ($legacyTier !== null && $legacyTier !== '0') {
                $tier = $this->tier($type, $legacyTier);
                if ($tier === null) {
                    $hinweise[] = 'Preisstufe ' . $type . ' ohne Zuordnung';
                }
            }
            $data[$spec['column']] = $tier;
        }

        return ['legacy_id' => $legacyId, 'data' => $data, 'hinweise' => $hinweise, 'eingang_ersetzt' => $createdFallback];
    }

    private function lookup(string $section, string $key): mixed
    {
        $map = is_array($this->mapping[$section] ?? null) ? $this->mapping[$section] : [];
        foreach ($map as $from => $to) {
            if ((string) $from === $key) {
                return $to;
            }
        }

        return null;
    }

    private function tier(string $type, string $legacyTier): ?int
    {
        $mapped = is_array($this->mapping['preisstufen'][$type] ?? null) ? $this->mapping['preisstufen'][$type] : [];
        foreach ($mapped as $from => $to) {
            if ((string) $from === $legacyTier && is_int($to)) {
                return $this->tierExists($to) ? $to : null;
            }
        }
        if (!ctype_digit($legacyTier)) {
            return null;
        }
        $cacheKey = $type . '|' . $legacyTier;
        if (!array_key_exists($cacheKey, $this->tierCache)) {
            $stmt = $this->pdo->prepare('SELECT id FROM price_tiers WHERE legacy_id = ? AND unit_type = ? ORDER BY valid_from DESC, id DESC LIMIT 1');
            $stmt->execute([(int) $legacyTier, self::TIER_TYPES[$type]['unit']]);
            $id = $stmt->fetchColumn();
            $this->tierCache[$cacheKey] = $id === false ? null : (int) $id;
        }

        return $this->tierCache[$cacheKey];
    }

    private function tierExists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM price_tiers WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    private static function cut(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }

    /**
     * Verwaltungsbeginn als Monatserster (Spalte leads.management_start).
     */
    public static function parseMonth(string $value): ?string
    {
        $value = trim($value);
        foreach (['!Y-m-d H:i:s', '!Y-m-d', '!d.m.Y', '!Y-m', '!m.Y', '!m/Y', '!d.m.y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-01');
            }
        }

        return null;
    }

    public static function parseDateTime(string $value, DateTimeZone $zone): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '0000')) {
            return null;
        }
        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d\TH:i:s', '!Y-m-d', '!d.m.Y H:i:s', '!d.m.Y H:i', '!d.m.Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, $zone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+\-]\d{2}:?\d{2})$/', $value) === 1) {
            try {
                return new DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * Führt den Import aus. Im Trockenlauf wird nichts geschrieben.
     *
     * @param list<array<string, ?string>> $rows
     * @return array{zaehler: array<string, int>, eintraege: list<array{legacy_id: ?int, aktion: string, grund: ?string}>}
     */
    public function import(array $rows, bool $dryRun, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::now();
        $zaehler = ['gelesen' => 0, 'neu' => 0, 'aktualisiert' => 0, 'unveraendert' => 0, 'uebersprungen' => 0, 'fehler' => 0, 'hinweise' => 0];
        $eintraege = [];
        $seen = [];
        $repo = new LeadRepository($this->pdo);
        $status = in_array($this->mapping['status'] ?? null, LeadRepository::STATUS, true) ? (string) $this->mapping['status'] : 'neu';
        $timestamp = $now->format('Y-m-d H:i:s');

        if (!$dryRun) {
            $this->pdo->beginTransaction();
        }
        try {
            foreach ($rows as $row) {
                $zaehler['gelesen']++;
                try {
                    $mapped = $this->mapRow($row, $now);
                } catch (\DomainException $e) {
                    $rawId = self::nullable($row['ID'] ?? null);
                    $zaehler['fehler']++;
                    $eintraege[] = ['legacy_id' => $rawId !== null && ctype_digit($rawId) ? (int) $rawId : null, 'aktion' => 'fehler', 'grund' => $e->getMessage()];
                    continue;
                }
                $legacyId = $mapped['legacy_id'];
                if (isset($seen[$legacyId])) {
                    $zaehler['fehler']++;
                    $eintraege[] = ['legacy_id' => $legacyId, 'aktion' => 'fehler', 'grund' => 'ID mehrfach in der Datei'];
                    continue;
                }
                $seen[$legacyId] = true;
                if ($mapped['hinweise'] !== []) {
                    $zaehler['hinweise']++;
                }
                $grund = $mapped['hinweise'] === [] ? null : implode(', ', $mapped['hinweise']);

                $existing = $this->existing($legacyId);
                if ($existing === null) {
                    if (!$dryRun) {
                        $leadId = $repo->insert($mapped['data'] + [
                            'uuid' => Uuid::v4(),
                            'legacy_id' => $legacyId,
                            'status' => $status,
                            'updated_at' => $timestamp,
                        ]);
                        $repo->addEvent($leadId, 'import');
                    }
                    $zaehler['neu']++;
                    $eintraege[] = ['legacy_id' => $legacyId, 'aktion' => 'neu', 'grund' => $grund];
                    continue;
                }
                if ($existing['anonymized_at'] !== null) {
                    $zaehler['uebersprungen']++;
                    $eintraege[] = ['legacy_id' => $legacyId, 'aktion' => 'uebersprungen', 'grund' => 'Lead ist anonymisiert'];
                    continue;
                }
                $compare = $mapped['data'];
                if ($mapped['eingang_ersetzt']) {
                    // Ersatzzeitpunkt ändert sich bei jedem Lauf, würde sonst jede Zeile als geändert melden
                    $compare['created_at'] = $existing['created_at'];
                }
                $changes = self::diff($existing, $compare);
                if ($changes === []) {
                    $zaehler['unveraendert']++;
                    $eintraege[] = ['legacy_id' => $legacyId, 'aktion' => 'unveraendert', 'grund' => null];
                    continue;
                }
                if (!$dryRun) {
                    $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($changes)));
                    $this->pdo->prepare('UPDATE leads SET ' . $set . ', updated_at = ? WHERE id = ?')
                        ->execute(array_merge(array_values($changes), [$timestamp, (int) $existing['id']]));
                    $repo->addEvent((int) $existing['id'], 'import', null, null, null, 'aktualisiert');
                }
                $zaehler['aktualisiert']++;
                $eintraege[] = ['legacy_id' => $legacyId, 'aktion' => 'aktualisiert', 'grund' => $grund];
            }
            if (!$dryRun) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!$dryRun && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return ['zaehler' => $zaehler, 'eintraege' => $eintraege];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function existing(int $legacyId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, anonymized_at, ' . implode(', ', self::IMPORT_COLUMNS) . ' FROM leads WHERE legacy_id = ?');
        $stmt->execute([$legacyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $new
     * @return array<string, mixed> geänderte Spalten mit neuem Wert
     */
    private static function diff(array $existing, array $new): array
    {
        $changes = [];
        foreach (self::IMPORT_COLUMNS as $column) {
            $old = $existing[$column] ?? null;
            $value = $new[$column] ?? null;
            if (($old === null) !== ($value === null) || (string) $old !== (string) $value) {
                $changes[$column] = $value;
            }
        }

        return $changes;
    }

    /**
     * Importprotokoll ohne personenbezogene Daten: Kopf mit Prüfsumme der Quelldatei, Zählwerte, je Zeile legacy_id, Aktion, Grund.
     *
     * @param array{zaehler: array<string, int>, eintraege: list<array{legacy_id: ?int, aktion: string, grund: ?string}>} $result
     */
    public static function protocol(array $result, string $sourceChecksum, bool $dryRun, ?DateTimeImmutable $now = null): string
    {
        $now ??= Clock::now();
        $lines = [
            'Import Altbestand (Tabelle Properties)',
            'Zeitpunkt (UTC): ' . $now->format('Y-m-d H:i:s'),
            'Modus: ' . ($dryRun ? 'Trockenlauf, nichts geschrieben' : 'Import'),
            'Quelldatei SHA-256: ' . $sourceChecksum,
            '',
        ];
        foreach ($result['zaehler'] as $key => $count) {
            $lines[] = sprintf('%s: %d', $key, $count);
        }
        $lines[] = '';
        foreach ($result['eintraege'] as $entry) {
            $lines[] = sprintf(
                'legacy_id=%s aktion=%s%s',
                $entry['legacy_id'] === null ? '?' : (string) $entry['legacy_id'],
                $entry['aktion'],
                $entry['grund'] === null ? '' : ' grund=' . $entry['grund']
            );
        }

        return implode("\n", $lines) . "\n";
    }
}
