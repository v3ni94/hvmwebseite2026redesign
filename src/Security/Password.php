<?php

declare(strict_types=1);

namespace Hvm\Security;

/**
 * Passwort-Hashing und Passwortregeln für den Admin-Bereich.
 *
 * Algorithmus: Argon2id, falls PHP damit gebaut ist, sonst PASSWORD_DEFAULT (bcrypt).
 * needsRehash() erkennt veraltete Hashes, die Anmeldung erneuert sie dann.
 */
final class Password
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 256;

    /** Häufige Passwörter und Muster, die trotz Länge abgelehnt werden (Kleinschreibung, ohne Ziffern am Ende). */
    private const BLOCKLIST = [
        'passwort', 'password', 'hausverwaltung', 'muellerhv', 'mueller', 'müller', 'admin', 'qwertz', 'qwerty',
        'geheim', 'letmein', 'willkommen', 'welcome', 'abc123', 'iloveyou', 'sommer', 'winter',
    ];

    private static ?string $dummyHash = null;

    public static function algorithm(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    public static function verify(string $password, string $hash): bool
    {
        if (strlen($password) > self::MAX_LENGTH * 4) {
            return false;
        }

        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }

    /**
     * Prüft gegen einen festen Hash, damit unbekannte Konten ungefähr gleich lange brauchen wie bekannte.
     */
    public static function verifyDummy(string $password): void
    {
        self::$dummyHash ??= self::hash(bin2hex(random_bytes(16)));
        password_verify($password, self::$dummyHash);
    }

    /**
     * Passwortregeln. Liefert Fehlermeldungen (leer = in Ordnung).
     *
     * @return list<string>
     */
    public static function policyErrors(string $password, string $email = ''): array
    {
        $errors = [];
        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            $errors[] = sprintf('Das Passwort muss mindestens %d Zeichen lang sein.', self::MIN_LENGTH);
        }
        if ($length > self::MAX_LENGTH) {
            $errors[] = sprintf('Das Passwort darf höchstens %d Zeichen lang sein.', self::MAX_LENGTH);
        }
        if ($length > 0 && count(array_unique(mb_str_split($password))) < 6) {
            $errors[] = 'Das Passwort enthält zu wenige unterschiedliche Zeichen.';
        }
        $normalized = mb_strtolower((string) preg_replace('/[\d\W_]+$/u', '', $password));
        if (in_array($normalized, self::BLOCKLIST, true)) {
            $errors[] = 'Das Passwort ist zu leicht zu erraten.';
        }
        $local = mb_strtolower(explode('@', $email)[0]);
        if ($local !== '' && mb_strlen($local) >= 4 && str_contains(mb_strtolower($password), $local)) {
            $errors[] = 'Das Passwort darf die E-Mail-Adresse nicht enthalten.';
        }

        return $errors;
    }
}
