<?php

declare(strict_types=1);

namespace Hvm\Security;

use Hvm\Support\Clock;
use Hvm\Support\Config;
use PDO;

/**
 * Wiederherstellungscodes für die Admin-Anmeldung, falls die Authenticator-App nicht verfügbar ist.
 *
 * - Je Konto COUNT Einmalcodes aus 10 Zeichen (Alphabet ohne verwechselbare Zeichen, rund 50 Bit),
 *   angezeigt als XXXXX-XXXXX. Erzeugen ersetzt alle bisherigen Codes des Kontos.
 * - Gespeichert wird nur ein HMAC-SHA256 mit einem aus APP_KEY abgeleiteten Schlüssel. Ohne APP_KEY
 *   lässt sich aus einer Datenbankkopie kein Code berechnen oder durchprobieren.
 * - Verbrauch atomar (UPDATE mit Bedingung used_at IS NULL), mit Zeitpunkt und IP-Hash protokolliert.
 */
final class RecoveryCodes
{
    public const COUNT = 10;
    public const LENGTH = 10;
    /** Ohne 0, 1, I, L, O und U: gut ablesbar, kein Verwechseln beim Abtippen */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
    public const CRYPTO_PURPOSE = 'admin-recovery-code';

    private readonly string $key;

    public function __construct(private readonly PDO $pdo, Config $config)
    {
        $this->key = SpamGuard::deriveKey($config, self::CRYPTO_PURPOSE);
    }

    /**
     * Großbuchstaben, ohne Leerraum und Bindestriche. Ergebnis leer, wenn die Eingabe kein Code sein kann.
     */
    public static function normalize(string $code): string
    {
        $code = strtoupper((string) preg_replace('/[\s\-]+/', '', $code));
        if (strlen($code) !== self::LENGTH || strspn($code, self::ALPHABET) !== self::LENGTH) {
            return '';
        }

        return $code;
    }

    /**
     * Unterscheidet einen Wiederherstellungscode von einem sechsstelligen TOTP-Code.
     */
    public static function looksLikeCode(string $input): bool
    {
        return self::normalize($input) !== '';
    }

    public static function format(string $code): string
    {
        return substr($code, 0, 5) . '-' . substr($code, 5);
    }

    public function hash(string $normalized): string
    {
        return hash_hmac('sha256', 'recovery|' . $normalized, $this->key);
    }

    /**
     * Ersetzt alle Codes des Kontos durch neue und gibt sie einmalig im Klartext zurück (formatiert).
     *
     * @return list<string>
     */
    public function regenerate(int $adminUserId): array
    {
        $codes = [];
        while (count($codes) < self::COUNT) {
            $code = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $codes[$code] = true;
        }
        $codes = array_keys($codes);

        $now = Clock::now()->format('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM admin_recovery_codes WHERE admin_user_id = ?')->execute([$adminUserId]);
            $insert = $this->pdo->prepare('INSERT INTO admin_recovery_codes (admin_user_id, code_hash, created_at) VALUES (?, ?, ?)');
            foreach ($codes as $code) {
                $insert->execute([$adminUserId, $this->hash($code), $now]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return array_map(self::format(...), $codes);
    }

    /**
     * Verbraucht einen Code. true nur, wenn er zum Konto gehört und noch nicht verwendet wurde.
     */
    public function consume(int $adminUserId, string $input, string $ipHash): bool
    {
        $normalized = self::normalize($input);
        if ($normalized === '') {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE admin_recovery_codes SET used_at = ?, used_ip_hash = ? WHERE admin_user_id = ? AND code_hash = ? AND used_at IS NULL'
        );
        $stmt->execute([Clock::now()->format('Y-m-d H:i:s'), $ipHash, $adminUserId, $this->hash($normalized)]);

        return $stmt->rowCount() === 1;
    }

    public function remaining(int $adminUserId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM admin_recovery_codes WHERE admin_user_id = ? AND used_at IS NULL');
        $stmt->execute([$adminUserId]);

        return (int) $stmt->fetchColumn();
    }
}
