<?php

declare(strict_types=1);

namespace Hvm\Tests\Integration;

use PDO;

/**
 * Alle Migrationen laufen auf einer leeren Testdatenbank fehlerfrei und sind idempotent protokolliert.
 */
final class MigrationsTest extends IntegrationTestCase
{
    private const TABELLEN = [
        'admin_users', 'price_tiers', 'leads', 'lead_events', 'contact_requests',
        'job_applications', 'outbox', 'rate_limits', 'admin_login_attempts',
    ];

    public function testMigrationsRunOnEmptyDatabase(): void
    {
        $pdo = $this->db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        self::assertSame([], $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));

        [$code, $output] = self::runPhp('bin/migrate.php', ['--database=test']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('0003_leads ... ok', $output);

        [$code, $output] = self::runPhp('bin/migrate.php', ['--database=test']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Keine offenen Migrationen', $output);

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (self::TABELLEN as $table) {
            self::assertContains($table, $tables);
        }
    }

    /**
     * Zwei gleichzeitige Läufe (Deploy-Skript und manueller Aufruf) dürfen sich nicht gegenseitig stören.
     * Ohne Sperre scheiterte einer mit "Table ... already exists".
     */
    public function testConcurrentMigrationRunsAreSerialized(): void
    {
        $pdo = $this->db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $processes = [];
        for ($i = 0; $i < 2; $i++) {
            $process = proc_open(
                [PHP_BINARY, self::basePath() . '/bin/migrate.php', '--database=test'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                self::basePath(),
                self::processEnv()
            );
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $output);
        }
        self::assertContains('leads', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testTablesUseInnoDbAndUtf8mb4UnicodeCi(): void
    {
        $stmt = $this->db()->prepare('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!in_array($row['TABLE_NAME'], self::TABELLEN, true)) {
                continue;
            }
            self::assertSame('InnoDB', $row['ENGINE'], $row['TABLE_NAME']);
            self::assertSame('utf8mb4_unicode_ci', $row['TABLE_COLLATION'], $row['TABLE_NAME']);
        }
    }

    public function testLeadsHasAllFieldsFromMasterprompt(): void
    {
        $columns = $this->db()->query('SHOW COLUMNS FROM leads')->fetchAll(PDO::FETCH_COLUMN);
        $expected = [
            'id', 'uuid', 'created_at', 'updated_at', 'management_form',
            'contact_salutation', 'contact_first_name', 'contact_last_name', 'contact_email', 'contact_phone', 'contact_role',
            'contact_street', 'contact_zip', 'contact_city',
            'object_street', 'object_zip', 'object_city', 'year_of_construction',
            'units_residential', 'units_commercial', 'units_parking',
            'management_start', 'has_current_manager', 'message',
            'price_tier_residential_id', 'price_tier_commercial_id', 'price_tier_parking_id',
            'source', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'landing_page', 'referrer',
            'consent_text_version', 'consent_at', 'status', 'assigned_to', 'notes', 'legacy_id',
        ];
        foreach ($expected as $column) {
            self::assertContains($column, $columns);
        }

        $status = $this->row("SHOW COLUMNS FROM leads LIKE 'status'");
        self::assertSame("enum('neu','kontaktiert','angebot','gewonnen','verloren','spam')", $status['Type']);

        $indexes = $this->db()->query('SHOW INDEX FROM leads')->fetchAll(PDO::FETCH_ASSOC);
        $unique = [];
        $firstColumns = [];
        foreach ($indexes as $index) {
            if ((int) $index['Seq_in_index'] === 1) {
                $firstColumns[] = $index['Column_name'];
                if ((int) $index['Non_unique'] === 0) {
                    $unique[] = $index['Column_name'];
                }
            }
        }
        self::assertContains('uuid', $unique);
        self::assertContains('legacy_id', $unique);
        foreach (['status', 'management_form', 'object_zip', 'source', 'created_at'] as $filter) {
            self::assertContains($filter, $firstColumns, 'Index für Filter ' . $filter);
        }
    }

    public function testForeignKeysToPriceTiers(): void
    {
        $stmt = $this->db()->prepare(
            'SELECT COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $stmt->execute(['leads']);
        $fks = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame('price_tiers', $fks['price_tier_residential_id'] ?? null);
        self::assertSame('price_tiers', $fks['price_tier_commercial_id'] ?? null);
        self::assertSame('price_tiers', $fks['price_tier_parking_id'] ?? null);
        self::assertSame('admin_users', $fks['assigned_to'] ?? null);

        $stmt->execute(['lead_events']);
        self::assertSame('leads', $stmt->fetchAll(PDO::FETCH_KEY_PAIR)['lead_id'] ?? null);
    }

    public function testMigrationsContainNoPrices(): void
    {
        foreach (glob(self::basePath() . '/migrations/*.sql') ?: [] as $file) {
            self::assertDoesNotMatchRegularExpression('/INSERT\s+INTO\s+`?price_tiers/i', (string) file_get_contents($file), basename($file));
        }
    }
}
