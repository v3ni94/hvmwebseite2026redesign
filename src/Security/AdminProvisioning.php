<?php

declare(strict_types=1);

namespace Hvm\Security;

use Hvm\Support\Clock;
use Hvm\Support\Config;
use PDO;

/**
 * Legt Admin-Konten mit TOTP-Geheimnis und Wiederherstellungscodes an.
 * Genutzt von bin/admin-user.php create und der Web-Einrichtung /_einrichtung/.
 *
 * Geheimnis und Codes werden nur zurückgegeben (einmalige Anzeige), nie protokolliert.
 */
final class AdminProvisioning
{
    public const ISSUER = 'Hausverwaltung Müller';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    }

    public function exists(string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM admin_users WHERE email = ?');
        $stmt->execute([AdminAuth::normalizeEmail($email)]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Prüft E-Mail und Passwort. Liefert Fehlermeldungen (leer = in Ordnung).
     *
     * @return list<string>
     */
    public function validate(string $email, string $password): array
    {
        $email = AdminAuth::normalizeEmail($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            return ['Bitte eine gültige E-Mail-Adresse angeben.'];
        }
        if (!SpamGuard::hasAppKey($this->config)) {
            return ['APP_KEY fehlt. Ohne APP_KEY kann das TOTP-Geheimnis nicht verschlüsselt werden.'];
        }
        if ($this->exists($email)) {
            return ['Ein Konto mit dieser E-Mail-Adresse existiert bereits.'];
        }

        return Password::policyErrors($password, $email);
    }

    /**
     * @return array{id: int, email: string, secret: string, uri: string, codes: list<string>}
     */
    public function create(string $email, string $password): array
    {
        $errors = $this->validate($email, $password);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }
        $email = AdminAuth::normalizeEmail($email);
        $now = Clock::now()->format('Y-m-d H:i:s');
        $secret = Totp::generateSecret();
        $this->pdo->prepare(
            'INSERT INTO admin_users (email, password_hash, totp_secret, totp_enabled, created_at, password_changed_at) VALUES (?, ?, ?, 1, ?, ?)'
        )->execute([$email, Password::hash($password), AdminAuth::encryptSecret($this->config, $secret), $now, $now]);
        $id = (int) $this->pdo->lastInsertId();
        $codes = (new RecoveryCodes($this->pdo, $this->config))->regenerate($id);

        return ['id' => $id, 'email' => $email, 'secret' => $secret, 'uri' => Totp::uri($secret, $email, self::ISSUER), 'codes' => $codes];
    }
}
