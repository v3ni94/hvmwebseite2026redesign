<?php

declare(strict_types=1);

namespace Hvm\Tests\Integration;

use Hvm\Http\Kernel;
use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Security\RecoveryCodes;
use Hvm\Security\Totp;
use PDO;

/**
 * Web-Einrichtung gegen die Testdatenbank: Migrationen auf leerer Datenbank, erster Admin mit TOTP und
 * Wiederherstellungscodes, Anmeldung mit dem angezeigten Geheimnis, kein zweiter Admin über die Einrichtung.
 */
final class EinrichtungFlowTest extends AdminTestCase
{
    private const TOKEN = 'fiktiver-einrichtungs-token-integration-0123';
    private const IP = '198.51.100.44';

    private string $storage = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir() . '/hvm-einrichtung-int-' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storage . '/{,*/}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        @rmdir($this->storage . '/ratelimit');
        @rmdir($this->storage);
        parent::tearDown();
    }

    private function setupKernel(): Kernel
    {
        $kernel = $this->kernel(['APP_ENV' => 'staging', 'SETUP_TOKEN' => self::TOKEN]);
        $kernel->config()->set('app.setup_storage', $this->storage);

        return $kernel;
    }

    private function request(Kernel $kernel, string $method, string $uri, array $post = []): Response
    {
        return $kernel->handle(Request::create($method, $uri, $post, ['REMOTE_ADDR' => self::IP]));
    }

    private function post(Kernel $kernel, string $uri, array $fields): Response
    {
        $page = $this->request($kernel, 'GET', $uri);
        self::assertSame(1, preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $page->body(), $m), 'CSRF-Feld fehlt');

        return $this->request($kernel, 'POST', $uri, $fields + ['_csrf' => $m[1]]);
    }

    private function login(Kernel $kernel): void
    {
        $response = $this->post($kernel, '/_einrichtung/', ['aktion' => 'anmelden', 'token' => self::TOKEN]);
        self::assertSame(303, $response->status(), $response->body());
    }

    private function dropAllTables(): void
    {
        $pdo = $this->db();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testMigrationsRunFromBrowserOnEmptyDatabase(): void
    {
        $this->dropAllTables();
        $kernel = $this->setupKernel();
        $this->login($kernel);

        $overview = $this->request($kernel, 'GET', '/_einrichtung/');
        self::assertStringContainsString('offene Migration(en)', $overview->body());
        self::assertStringContainsString('Migrationen ausführen', $overview->body());

        $response = $this->post($kernel, '/_einrichtung/', ['aktion' => 'migrieren']);
        self::assertSame(200, $response->status(), strip_tags($response->body()));
        self::assertStringContainsString('Migration 0001_admin_users ... ok', $response->body());
        self::assertStringContainsString('Alle Migrationen sind ausgeführt.', $response->body());

        $tables = $this->db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['schema_migrations', 'admin_users', 'leads', 'outbox', 'admin_recovery_codes'] as $table) {
            self::assertContains($table, $tables);
        }

        // zweiter Lauf: nichts offen
        $again = $this->post($kernel, '/_einrichtung/', ['aktion' => 'migrieren']);
        self::assertStringContainsString('Keine offenen Migrationen.', $again->body());
    }

    public function testFirstAdminIsCreatedOnceAndCanLogIn(): void
    {
        $kernel = $this->setupKernel();
        $this->login($kernel);

        $weak = $this->post($kernel, '/_einrichtung/', ['aktion' => 'admin', 'email' => 'erstadmin@example.org', 'passwort' => 'kurz', 'passwort_wiederholung' => 'kurz']);
        self::assertSame(422, $weak->status());
        self::assertSame(0, $this->countRows('admin_users'));

        $mismatch = $this->post($kernel, '/_einrichtung/', ['aktion' => 'admin', 'email' => 'erstadmin@example.org', 'passwort' => self::PASSWORD, 'passwort_wiederholung' => self::PASSWORD . 'x']);
        self::assertSame(422, $mismatch->status());

        $response = $this->post($kernel, '/_einrichtung/', ['aktion' => 'admin', 'email' => 'erstadmin@example.org', 'passwort' => self::PASSWORD, 'passwort_wiederholung' => self::PASSWORD]);
        self::assertSame(200, $response->status(), strip_tags($response->body()));
        self::assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
        $html = $response->body();

        self::assertSame(1, preg_match('#<dt>Geheimnis</dt><dd><code[^>]*>([A-Z2-7 ]+)</code>#', $html, $m), 'Geheimnis fehlt');
        $secret = str_replace(' ', '', $m[1]);
        self::assertStringContainsString('otpauth://totp/', $html);
        self::assertSame(RecoveryCodes::COUNT, preg_match_all('#<li><code>([23456789A-Z]{5}-[23456789A-Z]{5})</code></li>#', $html));

        $row = $this->row('SELECT email, totp_enabled, totp_secret FROM admin_users');
        self::assertSame('erstadmin@example.org', $row['email']);
        self::assertSame(1, (int) $row['totp_enabled']);
        self::assertStringNotContainsString($secret, (string) $row['totp_secret']);
        self::assertSame(RecoveryCodes::COUNT, $this->countRows('admin_recovery_codes'));

        // Geheimnis und Codes erscheinen nicht erneut
        $overview = $this->request($kernel, 'GET', '/_einrichtung/');
        self::assertStringNotContainsString($m[1], $overview->body());
        self::assertStringContainsString('Es existiert bereits ein Admin-Konto', $overview->body());

        // kein zweiter Admin über die Einrichtung
        $second = $this->post($kernel, '/_einrichtung/', ['aktion' => 'admin', 'email' => 'zweit@example.org', 'passwort' => self::PASSWORD, 'passwort_wiederholung' => self::PASSWORD]);
        self::assertSame(409, $second->status());
        self::assertSame(1, $this->countRows('admin_users'));

        // Anmeldung im Admin-Bereich mit dem angezeigten Geheimnis
        $admin = $this->kernel(['APP_ENV' => 'staging']);
        $response = $this->post($admin, '/admin/login/', ['email' => 'erstadmin@example.org', 'passwort' => self::PASSWORD]);
        self::assertSame(303, $response->status());
        $response = $this->post($admin, '/admin/login/code/', ['code' => Totp::code($secret)]);
        self::assertSame(303, $response->status());
        self::assertSame('/admin/', $response->header('Location'));
        self::assertSame(200, $this->request($admin, 'GET', '/admin/')->status());
    }
}
