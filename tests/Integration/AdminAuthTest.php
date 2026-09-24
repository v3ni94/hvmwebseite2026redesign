<?php

declare(strict_types=1);

namespace Hvm\Tests\Integration;

use Hvm\Http\Session;
use Hvm\Security\AdminAuth;
use Hvm\Security\RecoveryCodes;
use Hvm\Security\Totp;
use Hvm\Support\Clock;

/**
 * Anmeldung mit Passwort und TOTP, Sperren je Konto und IP, Wiederverwendung von Codes, Sitzungsdauer.
 */
final class AdminAuthTest extends AdminTestCase
{
    private const IP = '198.51.100.20';

    private function auth(?\Hvm\Http\Kernel $kernel = null): AdminAuth
    {
        return ($kernel ?? $this->kernel())->container()->get(AdminAuth::class);
    }

    public function testSuccessfulLoginWithTotp(): void
    {
        Clock::freeze('2026-09-23 10:00:10');
        $kernel = $this->kernel();
        $admin = $this->createAdmin($kernel);
        $auth = $this->auth($kernel);

        self::assertSame('ok', $auth->attemptPassword('Admin@Example.org ', self::PASSWORD, self::IP)['status']);
        self::assertTrue($auth->hasPending());
        self::assertNull($auth->user());
        self::assertSame('ok', $auth->attemptTotp(Totp::code($admin['secret'], Clock::now()->getTimestamp()), self::IP)['status']);
        self::assertSame(['id' => $admin['id'], 'email' => 'admin@example.org'], $auth->user());

        $row = $this->row('SELECT last_login_at, totp_last_step, totp_secret FROM admin_users WHERE id = ?', [$admin['id']]);
        self::assertSame('2026-09-23 10:00:10', $row['last_login_at']);
        self::assertSame(intdiv(Clock::now()->getTimestamp(), 30), (int) $row['totp_last_step']);
        self::assertStringNotContainsString($admin['secret'], (string) $row['totp_secret']);
        self::assertSame(1, $this->countRows('admin_login_attempts', 'success = 1'));
    }

    public function testUnknownUserAndWrongPasswordGiveSameResult(): void
    {
        $kernel = $this->kernel();
        $this->createAdmin($kernel);
        $auth = $this->auth($kernel);
        self::assertSame(['status' => 'invalid', 'retry_after' => 0], $auth->attemptPassword('niemand@example.org', self::PASSWORD, self::IP));
        self::assertSame(['status' => 'invalid', 'retry_after' => 0], $auth->attemptPassword('admin@example.org', 'falsch-falsch-falsch', self::IP));
        self::assertFalse($auth->hasPending());
        // Weder E-Mail noch IP im Klartext
        $row = $this->row('SELECT email_hash, ip_hash FROM admin_login_attempts LIMIT 1');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row['email_hash']);
        self::assertStringNotContainsString('198.51', (string) $row['ip_hash']);
    }

    public function testAccountIsLockedInStagesAndLockIsNotExtendedWhileActive(): void
    {
        Clock::freeze('2026-09-23 10:00:00');
        $kernel = $this->kernel();
        $admin = $this->createAdmin($kernel);
        $auth = $this->auth($kernel);

        for ($i = 0; $i < 5; $i++) {
            self::assertSame('invalid', $auth->attemptPassword('admin@example.org', 'falsch-' . $i, '198.51.100.' . $i)['status']);
        }
        // Richtiges Passwort während der Sperre: abgewiesen, ohne weiteren Fehlversuch zu zählen
        $locked = $auth->attemptPassword('admin@example.org', self::PASSWORD, '203.0.113.9');
        self::assertSame('locked', $locked['status']);
        self::assertSame(60, $locked['retry_after']);
        self::assertSame(5, $this->countRows('admin_login_attempts'));
        self::assertNotNull($this->row('SELECT locked_until FROM admin_users WHERE id = ?', [$admin['id']])['locked_until']);

        Clock::freeze('2026-09-23 10:01:01');
        self::assertSame('ok', $auth->attemptPassword('admin@example.org', self::PASSWORD, '203.0.113.9')['status']);
    }

    public function testUnknownAccountsAreLockedAsWell(): void
    {
        Clock::freeze('2026-09-23 10:00:00');
        $auth = $this->auth();
        for ($i = 0; $i < 5; $i++) {
            $auth->attemptPassword('niemand@example.org', 'x', '198.51.100.' . $i);
        }
        self::assertSame('locked', $auth->attemptPassword('niemand@example.org', 'x', '203.0.113.9')['status']);
    }

    public function testIpIsLockedAcrossAccounts(): void
    {
        Clock::freeze('2026-09-23 10:00:00');
        $kernel = $this->kernel();
        $this->createAdmin($kernel);
        $auth = $this->auth($kernel);
        for ($i = 0; $i < 10; $i++) {
            $auth->attemptPassword('person' . $i . '@example.org', 'x', self::IP);
        }
        self::assertSame('locked', $auth->attemptPassword('admin@example.org', self::PASSWORD, self::IP)['status']);
        self::assertSame('ok', $auth->attemptPassword('admin@example.org', self::PASSWORD, '203.0.113.50')['status']);
    }

    /**
     * Parallele Fehlversuche dürfen die Kontosperre nicht überholen: Prüfung und Zählung laufen je Konto
     * serialisiert (GET_LOCK), sonst passieren alle gleichzeitig gestarteten Versuche die Sperrprüfung.
     */
    public function testParallelAttemptsDoNotBypassAccountLock(): void
    {
        $kernel = $this->kernel();
        $this->createAdmin($kernel);
        $anzahl = 12;
        $start = sprintf('%.3F', microtime(true) + 2.0);
        $env = self::processEnv(['APP_KEY' => self::APP_KEY]);
        $prozesse = [];
        for ($i = 0; $i < $anzahl; $i++) {
            // je Prozess eine andere IP, damit nur die Kontosperre greift
            $befehl = [PHP_BINARY, self::basePath() . '/tests/fixtures/admin/login-versuch.php', 'admin@example.org', 'falsch-' . $i, '198.51.100.' . (100 + $i), $start];
            $prozess = proc_open($befehl, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::basePath(), $env);
            self::assertIsResource($prozess);
            $prozesse[] = [$prozess, $pipes];
        }
        $ergebnisse = [];
        foreach ($prozesse as [$prozess, $pipes]) {
            $ausgabe = trim((string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($prozess);
            $ergebnisse[] = $ausgabe;
        }
        $zaehler = array_count_values($ergebnisse);

        self::assertSame($anzahl, ($zaehler['invalid'] ?? 0) + ($zaehler['locked'] ?? 0), implode(' | ', $ergebnisse));
        self::assertLessThanOrEqual(array_key_first(AdminAuth::ACCOUNT_STAGES), $zaehler['invalid'] ?? 0, 'Geprüfte Passwörter trotz Sperre: ' . json_encode($zaehler));
    }

    public function testIpv6LockCannotBeBypassedByRotatingWithinPrefix(): void
    {
        Clock::freeze('2026-09-23 10:00:00');
        $kernel = $this->kernel();
        $this->createAdmin($kernel);
        $auth = $this->auth($kernel);
        // Jeder Versuch mit einer anderen Adresse aus demselben /64 (typischer Privatanschluss)
        for ($i = 0; $i < 10; $i++) {
            $auth->attemptPassword('person' . $i . '@example.org', 'x', '2001:db8:4:7:' . dechex($i + 1) . '::1');
        }
        self::assertSame('locked', $auth->attemptPassword('admin@example.org', self::PASSWORD, '2001:db8:4:7:ffff::99')['status']);
        self::assertSame('ok', $auth->attemptPassword('admin@example.org', self::PASSWORD, '2001:db8:4:8::1')['status']);
    }

    public function testDelayStages(): void
    {
        self::assertSame(0, AdminAuth::delayFor(4, AdminAuth::ACCOUNT_STAGES));
        self::assertSame(60, AdminAuth::delayFor(5, AdminAuth::ACCOUNT_STAGES));
        self::assertSame(300, AdminAuth::delayFor(12, AdminAuth::ACCOUNT_STAGES));
        self::assertSame(3600, AdminAuth::delayFor(99, AdminAuth::ACCOUNT_STAGES));
    }

    public function testTotpCodeCannotBeReused(): void
    {
        Clock::freeze('2026-09-23 10:00:10');
        $kernel = $this->kernel();
        $admin = $this->createAdmin($kernel);
        $auth = $this->auth($kernel);
        $code = Totp::code($admin['secret'], Clock::now()->getTimestamp());

        $auth->attemptPassword('admin@example.org', self::PASSWORD, self::IP);
        self::assertSame('ok', $auth->attemptTotp($code, self::IP)['status']);
        $auth->logout();

        $auth->attemptPassword('admin@example.org', self::PASSWORD, self::IP);
        self::assertSame('invalid', $auth->attemptTotp($code, self::IP)['status']);
        // Code des nächsten Zeitschritts (Toleranz) ist gültig
        self::assertSame('ok', $auth->attemptTotp(Totp::code($admin['secret'], Clock::now()->getTimestamp() + 30), self::IP)['status']);
    }

    public function testTooManyWrongCodesRestartLogin(): void
    {
        Clock::freeze('2026-09-23 10:00:10');
        $kernel = $this->kernel();
        $this->createAdmin($kernel);
        $auth = $this->auth($kernel);
        $auth->attemptPassword('admin@example.org', self::PASSWORD, self::IP);
        for ($i = 1; $i < AdminAuth::PENDING_MAX_TRIES; $i++) {
            self::assertSame('invalid', $auth->attemptTotp('000000', self::IP)['status']);
        }
        self::assertSame('expired', $auth->attemptTotp('000000', self::IP)['status']);
        self::assertFalse($auth->hasPending());
    }

    public function testPendingLoginExpires(): void
    {
        Clock::freeze('2026-09-23 10:00:10');
        $kernel = $this->kernel();
        $admin = $this->createAdmin($kernel);
        $auth = $this->auth($kernel);
        $auth->attemptPassword('admin@example.org', self::PASSWORD, self::IP);
        Clock::freeze('2026-09-23 10:05:30');
        self::assertSame('expired', $auth->attemptTotp(Totp::code($admin['secret'], Clock::now()->getTimestamp()), self::IP)['status']);
    }

    public function testIdleTimeoutAndAbsoluteLifetime(): void
    {
        Clock::freeze('2026-09-23 08:00:00');
        $kernel = $this->kernel(['SESSION_IDLE_TIMEOUT' => '600', 'ADMIN_SESSION_MAX_LIFETIME' => '3600']);
        $admin = $this->createAdmin($kernel);
        $auth = $this->auth($kernel);
        $auth->attemptPassword('admin@example.org', self::PASSWORD, self::IP);
        $auth->attemptTotp(Totp::code($admin['secret'], Clock::now()->getTimestamp()), self::IP);

        // Aktivität alle 9 Minuten hält die Sitzung, bis die Höchstdauer erreicht ist
        $session = $kernel->container()->get(Session::class);
        for ($minute = 9; $minute <= 54; $minute += 9) {
            Clock::freeze(sprintf('2026-09-23 08:%02d:00', $minute));
            $fresh = new AdminAuth($this->db(), $session, $kernel->config(), $kernel->container()->get(\Hvm\Support\Log::class));
            self::assertNotNull($fresh->user(), 'Minute ' . $minute);
        }
        Clock::freeze('2026-09-23 09:00:01');
        $fresh = new AdminAuth($this->db(), $session, $kernel->config(), $kernel->container()->get(\Hvm\Support\Log::class));
        self::assertNull($fresh->user());
        self::assertSame('abgelaufen', $fresh->pullNotice());
    }

    public function testIdleTimeoutEndsSession(): void
    {
        Clock::freeze('2026-09-23 08:00:00');
        $kernel = $this->kernel(['SESSION_IDLE_TIMEOUT' => '600']);
        $admin = $this->createAdmin($kernel);
        $auth = $this->auth($kernel);
        $auth->attemptPassword('admin@example.org', self::PASSWORD, self::IP);
        $auth->attemptTotp(Totp::code($admin['secret'], Clock::now()->getTimestamp()), self::IP);
        Clock::freeze('2026-09-23 08:10:01');
        $fresh = new AdminAuth($this->db(), $kernel->container()->get(Session::class), $kernel->config(), $kernel->container()->get(\Hvm\Support\Log::class));
        self::assertNull($fresh->user());
    }

    public function testDisabledAccountCannotLoginAndLosesSession(): void
    {
        Clock::freeze('2026-09-23 10:00:10');
        $kernel = $this->kernel();
        $admin = $this->createAdmin($kernel);
        $auth = $this->auth($kernel);
        $auth->attemptPassword('admin@example.org', self::PASSWORD, self::IP);
        $auth->attemptTotp(Totp::code($admin['secret'], Clock::now()->getTimestamp()), self::IP);
        $this->db()->prepare('UPDATE admin_users SET disabled_at = ? WHERE id = ?')->execute(['2026-09-23 10:00:20', $admin['id']]);

        $fresh = new AdminAuth($this->db(), $kernel->container()->get(Session::class), $kernel->config(), $kernel->container()->get(\Hvm\Support\Log::class));
        self::assertNull($fresh->user());
        self::assertSame('invalid', $fresh->attemptPassword('admin@example.org', self::PASSWORD, self::IP)['status']);
    }

    public function testIpAllowlist(): void
    {
        $auth = $this->auth($this->kernel(['ADMIN_IP_ALLOWLIST' => '203.0.113.0/24, 2001:db8::/32']));
        self::assertTrue($auth->ipAllowed('203.0.113.77'));
        self::assertTrue($auth->ipAllowed('2001:db8::1'));
        self::assertFalse($auth->ipAllowed('198.51.100.1'));
        self::assertFalse($auth->ipAllowed('2001:db9::1'));
        self::assertTrue($this->auth($this->kernel(['ADMIN_IP_ALLOWLIST' => null]))->ipAllowed('198.51.100.1'));
    }

    public function testRecoveryCodeReplacesTotpOnceAndIsLogged(): void
    {
        Clock::freeze('2026-09-23 10:00:10');
        $kernel = $this->kernel();
        $admin = $this->createAdmin($kernel);
        $codes = new RecoveryCodes($this->db(), $kernel->config());
        $plain = $codes->regenerate($admin['id']);
        self::assertCount(RecoveryCodes::COUNT, $plain);
        self::assertCount(RecoveryCodes::COUNT, array_unique($plain));
        foreach ($plain as $code) {
            self::assertMatchesRegularExpression('/^[23456789ABCDEFGHJKMNPQRSTVWXYZ]{5}-[23456789ABCDEFGHJKMNPQRSTVWXYZ]{5}$/', $code);
        }
        // Nur Hashes in der Datenbank
        $stored = $this->db()->query('SELECT code_hash FROM admin_recovery_codes')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(10, $stored);
        foreach ($plain as $code) {
            self::assertNotContains(str_replace('-', '', $code), $stored);
            self::assertNotContains($code, $stored);
        }

        $auth = $this->auth($kernel);
        self::assertSame('ok', $auth->attemptPassword('admin@example.org', self::PASSWORD, self::IP)['status']);
        // Kleinbuchstaben und Leerzeichen werden toleriert
        $result = $auth->attemptTotp(' ' . strtolower(str_replace('-', ' ', $plain[3])) . ' ', self::IP);
        self::assertSame(['status' => 'ok', 'retry_after' => 0, 'verbleibend' => 9], $result);
        self::assertSame($admin['id'], $auth->user()['id'] ?? null);
        self::assertSame(9, $codes->remaining($admin['id']));

        $used = $this->row('SELECT used_at, used_ip_hash FROM admin_recovery_codes WHERE used_at IS NOT NULL');
        self::assertSame('2026-09-23 10:00:10', $used['used_at']);
        self::assertSame($auth->ipHash(self::IP), $used['used_ip_hash']);
        self::assertNull($this->row('SELECT totp_last_step FROM admin_users WHERE id = ?', [$admin['id']])['totp_last_step']);
        $log = (string) @file_get_contents(self::basePath() . '/storage/logs/app.log');
        self::assertStringContainsString('Admin-Anmeldung mit Wiederherstellungscode {"admin_user_id":' . $admin['id'] . ',"verbleibend":9}', $log);

        // Derselbe Code ein zweites Mal: abgelehnt und als Fehlversuch gezählt
        $auth->logout();
        Clock::freeze('2026-09-23 10:05:00');
        self::assertSame('ok', $auth->attemptPassword('admin@example.org', self::PASSWORD, self::IP)['status']);
        self::assertSame('invalid', $auth->attemptTotp($plain[3], self::IP)['status']);
        self::assertSame(1, $this->countRows('admin_login_attempts', 'success = 0'));
        self::assertSame('ok', $auth->attemptTotp($plain[4], self::IP)['status']);
        self::assertSame(8, $codes->remaining($admin['id']));
    }

    public function testRegeneratingInvalidatesOldCodesAndCodesAreBoundToAccount(): void
    {
        Clock::freeze('2026-09-23 10:00:10');
        $kernel = $this->kernel();
        $admin = $this->createAdmin($kernel);
        $other = $this->createAdmin($kernel, 'zweit@example.org');
        $codes = new RecoveryCodes($this->db(), $kernel->config());
        $old = $codes->regenerate($admin['id']);
        $foreign = $codes->regenerate($other['id']);
        $new = $codes->regenerate($admin['id']);

        self::assertFalse($codes->consume($admin['id'], $old[0], 'x'), 'alter Code ungültig');
        self::assertFalse($codes->consume($admin['id'], $foreign[0], 'x'), 'Code eines anderen Kontos');
        self::assertFalse($codes->consume($admin['id'], '123456', 'x'), 'TOTP-Format ist kein Wiederherstellungscode');
        self::assertTrue($codes->consume($admin['id'], $new[0], 'x'));
        self::assertSame(20, $this->countRows('admin_recovery_codes'));
    }

    public function testAdminUserScriptCreatesAndRegeneratesRecoveryCodes(): void
    {
        [$code, $output] = self::runPhp('bin/admin-user.php', ['create', 'skript@example.org'], ['APP_KEY' => self::APP_KEY], "Fiktive-Passphrase-2026-Neu\n");
        self::assertSame(0, $code, $output);
        self::assertSame(1, preg_match_all('/Wiederherstellungscodes/', $output));
        self::assertSame(10, preg_match_all('/^\s+\d+\. [A-Z0-9]{5}-[A-Z0-9]{5}$/m', $output));
        $id = (int) $this->row('SELECT id FROM admin_users WHERE email = ?', ['skript@example.org'])['id'];
        self::assertSame(10, $this->countRows('admin_recovery_codes', 'admin_user_id = ?', [$id]));
        preg_match('/^\s+1\. ([A-Z0-9]{5}-[A-Z0-9]{5})$/m', $output, $first);

        [$code, $output] = self::runPhp('bin/admin-user.php', ['recovery-codes', 'skript@example.org'], ['APP_KEY' => self::APP_KEY]);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Alle bisherigen Codes sind ungültig', $output);
        self::assertSame(10, preg_match_all('/^\s+\d+\. [A-Z0-9]{5}-[A-Z0-9]{5}$/m', $output));
        self::assertSame(10, $this->countRows('admin_recovery_codes', 'admin_user_id = ?', [$id]));
        self::assertFalse((new RecoveryCodes($this->db(), $this->kernel()->config()))->consume($id, $first[1], 'x'));

        [$code, $output] = self::runPhp('bin/admin-user.php', ['list'], ['APP_KEY' => self::APP_KEY]);
        self::assertSame(0, $code, $output);
        self::assertMatchesRegularExpression('/skript@example\.org\s+ja\s+10\s+aktiv/', $output);
    }
}
