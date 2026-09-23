<?php

declare(strict_types=1);

namespace Hvm\Security;

/**
 * Zeitbasierte Einmalpasswörter nach RFC 6238 (HOTP nach RFC 4226 mit Zeitschritt).
 *
 * Standard: SHA1, 30 Sekunden, 6 Ziffern, wie von gängigen Authenticator-Apps erwartet.
 * verify() akzeptiert eine Toleranz von einem Zeitschritt in beide Richtungen und lehnt
 * Zeitschritte ab, die nicht größer sind als der zuletzt akzeptierte (Schutz vor Wiederverwendung).
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    public const WINDOW = 1;
    public const SECRET_BYTES = 20;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Neues Geheimnis (160 Bit), Base32 ohne Auffüllzeichen.
     */
    public static function generateSecret(int $bytes = self::SECRET_BYTES): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function timeStep(int $timestamp, int $period = self::PERIOD): int
    {
        return intdiv($timestamp, $period);
    }

    /**
     * HOTP-Wert für einen Zähler (RFC 4226 Abschnitt 5.3, dynamische Kürzung).
     *
     * @param string $key Binärschlüssel
     */
    public static function hotp(string $key, int $counter, int $digits = self::DIGITS, string $algo = 'sha1'): string
    {
        $message = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $hash = hash_hmac($algo, $message, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * TOTP-Wert zu einem Zeitpunkt.
     *
     * @param string $key Binärschlüssel
     */
    public static function at(string $key, int $timestamp, int $digits = self::DIGITS, string $algo = 'sha1', int $period = self::PERIOD): string
    {
        return self::hotp($key, self::timeStep($timestamp, $period), $digits, $algo);
    }

    /**
     * Aktueller Code zu einem Base32-Geheimnis (z. B. für Tests und Werkzeuge).
     */
    public static function code(string $base32Secret, ?int $timestamp = null): string
    {
        return self::at(self::base32Decode($base32Secret), $timestamp ?? time());
    }

    /**
     * Prüft einen Code. Liefert den akzeptierten Zeitschritt oder null.
     *
     * @param int|null $lastUsedStep zuletzt akzeptierter Zeitschritt; gleiche oder ältere Schritte werden abgelehnt
     */
    public static function verify(string $base32Secret, string $code, int $timestamp, ?int $lastUsedStep = null, int $window = self::WINDOW): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }
        $key = self::base32Decode($base32Secret);
        if ($key === '') {
            return null;
        }
        $current = self::timeStep($timestamp);
        $matched = null;
        // Alle Schritte des Fensters prüfen, damit die Laufzeit nicht vom Treffer abhängt.
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $current + $offset;
            if ($step < 0) {
                continue;
            }
            if (hash_equals(self::hotp($key, $step), $code) && ($lastUsedStep === null || $step > $lastUsedStep)) {
                $matched ??= $step;
            }
        }

        return $matched;
    }

    /**
     * otpauth-URI für Authenticator-Apps (Key-URI-Format).
     */
    public static function uri(string $base32Secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            $label,
            $base32Secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD
        );
    }

    public static function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($bits, 5) as $chunk) {
            $result .= self::BASE32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $result;
    }

    /**
     * Base32 (RFC 4648) ohne Beachtung von Groß- und Kleinschreibung, Leerzeichen und "=".
     * Ungültige Zeichen ergeben eine leere Zeichenkette.
     */
    public static function base32Decode(string $input): string
    {
        $input = strtoupper((string) preg_replace('/[\s=]+/', '', $input));
        if ($input === '' || strspn($input, self::BASE32) !== strlen($input)) {
            return '';
        }
        $bits = '';
        foreach (str_split($input) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::BASE32, $char)), 5, '0', STR_PAD_LEFT);
        }
        $result = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $result .= chr((int) bindec($byte));
            }
        }

        return $result;
    }
}
