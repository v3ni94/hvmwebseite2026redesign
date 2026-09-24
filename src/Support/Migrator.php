<?php

declare(strict_types=1);

namespace Hvm\Support;

use PDO;
use Throwable;

/**
 * Führt migrations/NNNN_name.sql in Reihenfolge aus und protokolliert sie in schema_migrations.
 * Genutzt von bin/migrate.php (Kommandozeile) und der Web-Einrichtung /_einrichtung/ (ohne Kommandozeile).
 *
 * Mehrere Anweisungen je Datei sind erlaubt (Trennung per Semikolon, Zeichenketten und Kommentare werden beachtet).
 * Gleichzeitige Läufe werden über GET_LOCK serialisiert.
 */
final class Migrator
{
    public const LOCK_TIMEOUT = 120;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /**
     * @return list<string> bereits ausgeführte Versionen
     */
    public function applied(): array
    {
        $this->ensureTable();

        return array_values(array_map('strval', $this->pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * @return list<string> Versionen aller Migrationsdateien, sortiert
     */
    public function available(): array
    {
        $files = is_dir($this->directory) ? (glob(rtrim($this->directory, '/') . '/*.sql') ?: []) : [];
        sort($files, SORT_STRING);

        return array_map(static fn (string $f): string => basename($f, '.sql'), $files);
    }

    /**
     * @return list<string> offene Versionen
     */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_filter($this->available(), static fn (string $v): bool => !in_array($v, $applied, true)));
    }

    /**
     * Führt alle offenen Migrationen aus.
     *
     * @param (callable(string): void)|null $output erhält Fortschrittszeilen ohne Zeilenumbruch am Ende
     * @return array{ok: bool, ausgefuehrt: list<string>, fehler: ?string, version: ?string}
     */
    public function migrate(?callable $output = null): array
    {
        $output ??= static function (string $line): void {
        };
        $lockName = 'hvm_migrate_' . substr(hash('sha256', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 40);
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $lock->execute([$lockName, self::LOCK_TIMEOUT]);
        if ((int) $lock->fetchColumn() !== 1) {
            return ['ok' => false, 'ausgefuehrt' => [], 'fehler' => 'Ein anderer Migrationslauf ist aktiv, Abbruch nach ' . self::LOCK_TIMEOUT . ' Sekunden Wartezeit.', 'version' => null];
        }

        $done = [];
        try {
            foreach ($this->pending() as $version) {
                try {
                    foreach (self::splitSql((string) file_get_contents(rtrim($this->directory, '/') . '/' . $version . '.sql')) as $statement) {
                        $this->pdo->exec($statement);
                    }
                    $this->pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, UTC_TIMESTAMP())')->execute([$version]);
                } catch (Throwable $e) {
                    $output("Migration $version ... Fehler");

                    return ['ok' => false, 'ausgefuehrt' => $done, 'fehler' => $e->getMessage(), 'version' => $version];
                }
                $output("Migration $version ... ok");
                $done[] = $version;
            }
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }

        return ['ok' => true, 'ausgefuehrt' => $done, 'fehler' => null, 'version' => null];
    }

    private function ensureTable(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(191) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /**
     * Zerlegt SQL in einzelne Anweisungen.
     *
     * @return list<string>
     */
    public static function splitSql(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $quote !== '`') {
                    $current .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    if ($next === $quote) {
                        $current .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                continue;
            }
            if (($char === '-' && $next === '-' && in_array($sql[$i + 2] ?? ' ', [' ', "\t", "\n", "\r"], true)) || $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                $current .= "\n";
                continue;
            }
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;
                $current .= ' ';
                continue;
            }
            if ($char === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }
}
