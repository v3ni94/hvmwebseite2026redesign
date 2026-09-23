<?php

declare(strict_types=1);

namespace Hvm\Security;

use Hvm\Http\IpRange;
use Hvm\Http\Session;
use Hvm\Support\Clock;
use Hvm\Support\Config;
use Hvm\Support\Env;
use Hvm\Support\Log;
use PDO;

/**
 * Anmeldung im Admin-Bereich (MP 6.5, MP 10): E-Mail und Passwort, danach TOTP.
 *
 * - Brute-Force-Schutz: gestaffelte Sperre je Konto (E-Mail-Hash) und je IP-Hash aus admin_login_attempts.
 *   Fehlversuche zählen seit der letzten erfolgreichen Anmeldung, höchstens 24 Stunden zurück.
 *   Während einer Sperre wird weder geprüft noch gezählt, damit ein Angreifer die Sperre nicht verlängert.
 * - Unbekannte Konten, deaktivierte Konten und falsche Passwörter ergeben dieselbe Antwort, die
 *   Passwortprüfung läuft auch für unbekannte Konten (konstante Laufzeit, soweit praktikabel).
 * - TOTP: Toleranz ein Zeitschritt, ein akzeptierter Code wird atomar verbraucht (totp_last_step).
 * - Sitzung: neue Sitzungs-ID nach Passwort und nach TOTP, Leerlauf-Timeout (SESSION_IDLE_TIMEOUT),
 *   absolute Höchstdauer (ADMIN_SESSION_MAX_LIFETIME, Standard 8 Stunden).
 *
 * E-Mail-Adressen und IP-Adressen werden nur als HMAC gespeichert, der Log enthält keine davon.
 */
final class AdminAuth
{
    public const SESSION_PENDING = '_admin_pending';
    public const SESSION_AUTH = '_admin_auth';
    public const SESSION_NOTICE = '_admin_hinweis';

    /** Zeit für die Eingabe des TOTP-Codes nach korrektem Passwort */
    public const PENDING_TTL = 300;
    /** Falsche Codes je Passwortanmeldung, danach beginnt die Anmeldung neu */
    public const PENDING_MAX_TRIES = 5;
    public const DEFAULT_MAX_LIFETIME = 28800;
    public const COUNT_WINDOW = 86400;

    /** Fehlversuche je Konto => Sperre in Sekunden (höchste erreichte Stufe gilt) */
    public const ACCOUNT_STAGES = [5 => 60, 10 => 300, 15 => 900, 20 => 3600];
    /** Fehlversuche je IP => Sperre in Sekunden. Großzügiger, da sich mehrere Personen eine IP teilen können. */
    public const IP_STAGES = [10 => 60, 20 => 300, 30 => 900, 50 => 3600];

    public const CRYPTO_PURPOSE = 'totp-secret';

    private readonly string $hashKey;

    /** @var array<string, mixed>|null|false false = noch nicht geladen */
    private array|null|false $current = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Session $session,
        private readonly Config $config,
        private readonly Log $log,
    ) {
        $this->hashKey = SpamGuard::deriveKey($config, 'admin-login');
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function emailHash(string $email): string
    {
        return hash_hmac('sha256', 'email|' . self::normalizeEmail($email), $this->hashKey);
    }

    public function ipHash(string $ip): string
    {
        return hash_hmac('sha256', 'ip|' . $ip, $this->hashKey);
    }

    /**
     * Verschlüsselt ein TOTP-Geheimnis für admin_users.totp_secret (secretbox, Schlüssel aus APP_KEY).
     * Format: base64(nonce || chiffrat), entschlüsselbar mit Hvm\Security\Crypto::decrypt().
     */
    public static function encryptSecret(Config $config, string $plain): string
    {
        $key = self::secretKey($config);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
        sodium_memzero($key);

        return base64_encode($nonce . $cipher);
    }

    public static function decryptSecret(Config $config, string $stored): string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false) {
            throw new \RuntimeException('TOTP-Geheimnis ist beschädigt.');
        }

        return Crypto::decrypt($raw, self::secretKey($config));
    }

    private static function secretKey(Config $config): string
    {
        if (!SpamGuard::hasAppKey($config)) {
            throw new \RuntimeException('APP_KEY fehlt, TOTP-Geheimnisse können nicht verschlüsselt werden.');
        }

        return SpamGuard::deriveKey($config, self::CRYPTO_PURPOSE);
    }

    public function idleTimeout(): int
    {
        return max(60, (int) $this->config->get('app.session.idle_timeout', 1800));
    }

    public function maxLifetime(): int
    {
        $configured = $this->config->get('app.admin_session_max_lifetime') ?? Env::int('ADMIN_SESSION_MAX_LIFETIME');

        return max(300, is_numeric($configured) ? (int) $configured : self::DEFAULT_MAX_LIFETIME);
    }

    /**
     * Optionale IP-Beschränkung (ADMIN_IP_ALLOWLIST, CIDR, IPv4 und IPv6). Leer = keine Beschränkung.
     */
    public function ipAllowed(string $ip): bool
    {
        $list = array_values(array_filter(array_map('trim', (array) $this->config->get('app.admin_ip_allowlist', []))));

        return $list === [] || IpRange::matchesAny($ip, $list);
    }

    /**
     * Erster Schritt: E-Mail und Passwort.
     *
     * @return array{status: 'ok'|'invalid'|'locked', retry_after: int}
     */
    public function attemptPassword(string $email, string $password, string $ip): array
    {
        $email = self::normalizeEmail($email);
        $emailHash = $this->emailHash($email);
        $ipHash = $this->ipHash($ip);

        $retry = $this->lockRetryAfter($emailHash, $ipHash);
        if ($retry > 0) {
            $this->log->warning('Admin-Anmeldung während Sperre abgewiesen', ['retry_after' => $retry]);

            return ['status' => 'locked', 'retry_after' => $retry];
        }

        $user = $email === '' ? null : $this->findUserByEmail($email);
        $valid = false;
        if ($user === null || $user['disabled_at'] !== null) {
            Password::verifyDummy($password);
        } else {
            $valid = Password::verify($password, (string) $user['password_hash']);
        }

        if (!$valid || $user === null || (int) $user['totp_enabled'] !== 1 || (string) ($user['totp_secret'] ?? '') === '') {
            if ($valid && $user !== null) {
                $this->log->warning('Admin-Anmeldung: Konto ohne eingerichtetes TOTP', ['admin_user_id' => (int) $user['id']]);
            }
            $this->recordFailure($emailHash, $ipHash, $user === null ? null : (int) $user['id']);

            return ['status' => 'invalid', 'retry_after' => 0];
        }

        if (Password::needsRehash((string) $user['password_hash'])) {
            $this->pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')
                ->execute([Password::hash($password), (int) $user['id']]);
        }

        $this->session->regenerate();
        $this->session->remove(self::SESSION_AUTH);
        $this->session->set(self::SESSION_PENDING, [
            'id' => (int) $user['id'],
            'at' => Clock::now()->getTimestamp(),
            'tries' => 0,
        ]);

        return ['status' => 'ok', 'retry_after' => 0];
    }

    public function hasPending(): bool
    {
        return $this->pending() !== null;
    }

    /**
     * @return array{id: int, at: int, tries: int}|null
     */
    private function pending(): ?array
    {
        $pending = $this->session->get(self::SESSION_PENDING);
        if (!is_array($pending) || !isset($pending['id'], $pending['at'])) {
            return null;
        }
        if (Clock::now()->getTimestamp() - (int) $pending['at'] > self::PENDING_TTL) {
            $this->session->remove(self::SESSION_PENDING);

            return null;
        }

        return ['id' => (int) $pending['id'], 'at' => (int) $pending['at'], 'tries' => (int) ($pending['tries'] ?? 0)];
    }

    /**
     * Zweiter Schritt: TOTP-Code.
     *
     * @return array{status: 'ok'|'invalid'|'expired'|'locked', retry_after: int}
     */
    public function attemptTotp(string $code, string $ip): array
    {
        $pending = $this->pending();
        if ($pending === null) {
            return ['status' => 'expired', 'retry_after' => 0];
        }
        $user = $this->findUserById($pending['id']);
        if ($user === null || $user['disabled_at'] !== null || (string) ($user['totp_secret'] ?? '') === '') {
            $this->session->remove(self::SESSION_PENDING);

            return ['status' => 'expired', 'retry_after' => 0];
        }

        $emailHash = $this->emailHash((string) $user['email']);
        $ipHash = $this->ipHash($ip);
        $retry = $this->lockRetryAfter($emailHash, $ipHash);
        if ($retry > 0) {
            $this->session->remove(self::SESSION_PENDING);

            return ['status' => 'locked', 'retry_after' => $retry];
        }

        $step = null;
        try {
            $secret = self::decryptSecret($this->config, (string) $user['totp_secret']);
            $last = $user['totp_last_step'] === null ? null : (int) $user['totp_last_step'];
            $step = Totp::verify($secret, $code, Clock::now()->getTimestamp(), $last);
            sodium_memzero($secret);
        } catch (\RuntimeException $e) {
            $this->log->error('Admin-Anmeldung: TOTP-Geheimnis nicht entschlüsselbar', ['admin_user_id' => (int) $user['id'], 'fehler' => $e->getMessage()]);
        }

        if ($step !== null) {
            // Atomar verbrauchen: parallele Anfragen mit demselben Code scheitern an der Bedingung.
            $stmt = $this->pdo->prepare('UPDATE admin_users SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)');
            $stmt->execute([$step, (int) $user['id'], $step]);
            if ($stmt->rowCount() !== 1) {
                $step = null;
            }
        }

        if ($step === null) {
            $this->recordFailure($emailHash, $ipHash, (int) $user['id']);
            $pending['tries']++;
            if ($pending['tries'] >= self::PENDING_MAX_TRIES) {
                $this->session->remove(self::SESSION_PENDING);

                return ['status' => 'expired', 'retry_after' => 0];
            }
            $this->session->set(self::SESSION_PENDING, $pending);

            return ['status' => 'invalid', 'retry_after' => 0];
        }

        $now = Clock::now();
        $this->recordAttempt($emailHash, $ipHash, true);
        $this->pdo->prepare('UPDATE admin_users SET last_login_at = ?, failed_logins = 0, locked_until = NULL WHERE id = ?')
            ->execute([$now->format('Y-m-d H:i:s'), (int) $user['id']]);

        $this->session->remove(self::SESSION_PENDING);
        $this->session->regenerate();
        // Neues CSRF-Token nach der Anmeldung (Schlüssel aus Hvm\Http\Middleware\Csrf)
        $this->session->remove('_csrf_token');
        $this->session->set(self::SESSION_AUTH, [
            'id' => (int) $user['id'],
            'login_at' => $now->getTimestamp(),
            'last_activity' => $now->getTimestamp(),
        ]);
        $this->current = false;
        $this->log->info('Admin-Anmeldung erfolgreich', ['admin_user_id' => (int) $user['id']]);

        return ['status' => 'ok', 'retry_after' => 0];
    }

    /**
     * Angemeldeter Benutzer oder null. Prüft Leerlauf, Höchstdauer und ob das Konto noch aktiv ist.
     *
     * @return array{id: int, email: string}|null
     */
    public function user(): ?array
    {
        if ($this->current !== false) {
            return $this->current;
        }
        $auth = $this->session->get(self::SESSION_AUTH);
        if (!is_array($auth) || !isset($auth['id'], $auth['login_at'], $auth['last_activity'])) {
            return $this->current = null;
        }
        $now = Clock::now()->getTimestamp();
        if ($now - (int) $auth['login_at'] > $this->maxLifetime() || $now - (int) $auth['last_activity'] > $this->idleTimeout()) {
            $this->endSession();
            $this->session->set(self::SESSION_NOTICE, 'abgelaufen');

            return $this->current = null;
        }
        $row = $this->findUserById((int) $auth['id']);
        if ($row === null || $row['disabled_at'] !== null) {
            $this->endSession();

            return $this->current = null;
        }
        $auth['last_activity'] = $now;
        $this->session->set(self::SESSION_AUTH, $auth);

        return $this->current = ['id' => (int) $row['id'], 'email' => (string) $row['email']];
    }

    public function logout(): void
    {
        $user = $this->user();
        $this->endSession();
        if ($user !== null) {
            $this->log->info('Admin-Abmeldung', ['admin_user_id' => $user['id']]);
        }
    }

    /**
     * Einmaliger Hinweis für die Anmeldeseite (z. B. "abgelaufen").
     */
    public function pullNotice(): ?string
    {
        $notice = $this->session->get(self::SESSION_NOTICE);
        if ($notice !== null) {
            $this->session->remove(self::SESSION_NOTICE);
        }

        return is_string($notice) ? $notice : null;
    }

    private function endSession(): void
    {
        $this->session->remove(self::SESSION_AUTH);
        $this->session->remove(self::SESSION_PENDING);
        $this->session->remove('_csrf_token');
        $this->session->regenerate();
        $this->current = null;
    }

    /**
     * Restdauer einer Sperre in Sekunden (0 = keine Sperre), Maximum aus Konto- und IP-Sperre.
     */
    public function lockRetryAfter(string $emailHash, string $ipHash): int
    {
        return max(
            $this->stageRetry('email_hash', $emailHash, self::ACCOUNT_STAGES),
            $this->stageRetry('ip_hash', $ipHash, self::IP_STAGES)
        );
    }

    /**
     * @param 'email_hash'|'ip_hash' $column
     * @param array<int, int>        $stages
     */
    private function stageRetry(string $column, string $hash, array $stages): int
    {
        $now = Clock::now();
        $since = $now->modify('-' . self::COUNT_WINDOW . ' seconds')->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS anzahl, MAX(created_at) AS zuletzt FROM admin_login_attempts
             WHERE ' . $column . ' = ? AND success = 0 AND created_at >= ?
               AND created_at > COALESCE((SELECT MAX(s.created_at) FROM admin_login_attempts s WHERE s.' . $column . ' = ? AND s.success = 1), \'1970-01-01 00:00:00\')'
        );
        $stmt->execute([$hash, $since, $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $count = (int) ($row['anzahl'] ?? 0);
        $delay = self::delayFor($count, $stages);
        if ($delay === 0 || !is_string($row['zuletzt'] ?? null)) {
            return 0;
        }
        $last = new \DateTimeImmutable($row['zuletzt'], new \DateTimeZone('UTC'));

        return max(0, $last->getTimestamp() + $delay - $now->getTimestamp());
    }

    /**
     * @param array<int, int> $stages
     */
    public static function delayFor(int $failures, array $stages): int
    {
        $delay = 0;
        foreach ($stages as $threshold => $seconds) {
            if ($failures >= $threshold) {
                $delay = $seconds;
            }
        }

        return $delay;
    }

    private function recordFailure(string $emailHash, string $ipHash, ?int $userId): void
    {
        $this->recordAttempt($emailHash, $ipHash, false);
        if ($userId !== null) {
            $retry = $this->stageRetry('email_hash', $emailHash, self::ACCOUNT_STAGES);
            $until = $retry > 0 ? Clock::now()->modify('+' . $retry . ' seconds')->format('Y-m-d H:i:s') : null;
            $this->pdo->prepare('UPDATE admin_users SET failed_logins = LEAST(failed_logins + 1, 65535), locked_until = ? WHERE id = ?')
                ->execute([$until, $userId]);
        }
        $this->log->info('Admin-Anmeldung fehlgeschlagen', $userId === null ? [] : ['admin_user_id' => $userId]);
    }

    private function recordAttempt(string $emailHash, string $ipHash, bool $success): void
    {
        $this->pdo->prepare('INSERT INTO admin_login_attempts (email_hash, ip_hash, success, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$emailHash, $ipHash, $success ? 1 : 0, Clock::now()->format('Y-m-d H:i:s')]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUserByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM admin_users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUserById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM admin_users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
