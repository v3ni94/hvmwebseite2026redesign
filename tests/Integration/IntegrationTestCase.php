<?php

declare(strict_types=1);

namespace Hvm\Tests\Integration;

use Hvm\Http\Kernel;
use Hvm\Support\Clock;
use Hvm\Support\Db;
use Hvm\Support\Env;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Basis für Tests gegen die Testdatenbank (DB_TEST_*, Standard hvm_test).
 * Ohne .env oder ohne erreichbare Datenbank werden die Tests übersprungen.
 * Das Schema entsteht über bin/migrate.php --database=test.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected const APP_KEY = 'base64:dGVzdHNjaGx1ZXNzZWwtbnVyLWZ1ZXItdGVzdHMtMzJi';

    /** @var array<string, string> */
    private static array $fileEnv = [];

    private static ?string $skipReason = null;

    protected static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        self::$skipReason = null;
        $file = self::basePath() . '/.env';
        self::$fileEnv = is_file($file) ? Env::parse((string) file_get_contents($file)) : [];
        if (self::testDb()['name'] === '') {
            self::$skipReason = 'DB_TEST_NAME nicht gesetzt';

            return;
        }
        try {
            self::$pdo = Db::connect(self::testDb());
        } catch (Throwable $e) {
            self::$skipReason = 'Testdatenbank nicht erreichbar: ' . get_class($e);

            return;
        }
        static::ensureSchema();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$skipReason !== null || self::$pdo === null) {
            self::markTestSkipped((string) self::$skipReason);
        }
        $this->cleanTables();
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        Env::reset();
        parent::tearDown();
    }

    protected static function basePath(): string
    {
        return dirname(__DIR__, 2);
    }

    protected static function env(string $key, string $default = ''): string
    {
        $real = getenv($key);
        if ($real !== false && $real !== '') {
            return $real;
        }
        $value = self::$fileEnv[$key] ?? '';

        return $value === '' ? $default : $value;
    }

    /**
     * @return array{host: string, port: int, socket: string, name: string, user: string, password: string}
     */
    protected static function testDb(): array
    {
        return [
            'host' => self::env('DB_TEST_HOST', self::env('DB_HOST', '127.0.0.1')),
            'port' => (int) self::env('DB_TEST_PORT', self::env('DB_PORT', '3306')),
            'socket' => self::env('DB_TEST_SOCKET', self::env('DB_SOCKET')),
            'name' => self::env('DB_TEST_NAME'),
            'user' => self::env('DB_TEST_USER', self::env('DB_USER')),
            'password' => self::env('DB_TEST_PASSWORD', self::env('DB_PASSWORD')),
        ];
    }

    /**
     * Umgebung für Prozesse (bin/migrate.php, bin/worker.php), die auf die Testdatenbank zeigen.
     *
     * @return array<string, string>
     */
    protected static function processEnv(array $extra = []): array
    {
        $db = self::testDb();

        return array_merge(getenv(), [
            'DB_HOST' => $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_SOCKET' => $db['socket'],
            'DB_NAME' => $db['name'],
            'DB_USER' => $db['user'],
            'DB_PASSWORD' => $db['password'],
            'DB_TEST_NAME' => $db['name'],
        ], $extra);
    }

    /**
     * @return array{0: int, 1: string} Exitcode und Ausgabe
     */
    protected static function runPhp(string $script, array $args = [], array $env = []): array
    {
        $command = array_merge([PHP_BINARY, self::basePath() . '/' . $script], $args);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::basePath(), self::processEnv($env));
        if (!is_resource($process)) {
            return [1, 'Prozess nicht gestartet'];
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $output];
    }

    protected static function ensureSchema(): void
    {
        $exists = self::$pdo?->query("SHOW TABLES LIKE 'leads'")->fetchColumn();
        if ($exists === false || $exists === null) {
            [$code, $output] = self::runPhp('bin/migrate.php', ['--database=test']);
            if ($code !== 0) {
                self::$skipReason = 'Migrationen fehlgeschlagen: ' . $output;
            }
        }
    }

    protected function cleanTables(): void
    {
        foreach (['outbox', 'lead_events', 'leads', 'rate_limits', 'price_tiers'] as $table) {
            self::$pdo?->exec('DELETE FROM ' . $table);
        }
    }

    protected function db(): PDO
    {
        if (self::$pdo === null) {
            self::fail('Keine Testdatenbank');
        }

        return self::$pdo;
    }

    /**
     * Kernel gegen die Testdatenbank, ohne Mail- und Webhook-Konfiguration.
     *
     * @param array<string, string|null> $env
     */
    protected function kernel(array $env = []): Kernel
    {
        $db = self::testDb();

        return Kernel::create(self::basePath(), array_merge([
            'APP_ENV' => 'development',
            'APP_URL' => 'https://www.muellerhv.de',
            'APP_KEY' => self::APP_KEY,
            'SESSION_DRIVER' => 'array',
            'DB_HOST' => $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_SOCKET' => $db['socket'],
            'DB_NAME' => $db['name'],
            'DB_USER' => $db['user'],
            'DB_PASSWORD' => $db['password'],
            'MAIL_HOST' => null,
            'MAIL_FROM' => null,
            'LEAD_NOTIFY_TO' => null,
            'N8N_WEBHOOK_URL' => null,
            'N8N_WEBHOOK_SECRET' => null,
            'PRICE_INDICATION_ENABLED' => 'false',
            'TRUSTED_PROXIES' => null,
        ], $env));
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(string $sql, array $params = []): array
    {
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    protected function countRows(string $table, string $where = '1=1', array $params = []): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
