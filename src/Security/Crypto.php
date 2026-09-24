<?php

declare(strict_types=1);

namespace Hvm\Security;

/**
 * Verschlüsselung von Dateien außerhalb des Webroots (MP 6, MP 10): libsodium secretbox mit einem
 * aus APP_KEY zweckgebundenen Schlüssel (SpamGuard::deriveKey). Format der Datei: Nonce (24 Byte)
 * gefolgt vom Chiffretext. Für Bewerbungsanhänge (wenige MB), daher kein Streaming-Verfahren nötig.
 */
final class Crypto
{
    /**
     * Verschlüsselt und schreibt in eine Datei (0600). Vorhandene Datei wird überschrieben.
     */
    public static function encryptToFile(string $plaintext, string $path, string $key): void
    {
        self::assertKey($key);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, $nonce . $cipher, LOCK_EX) === false) {
            throw new \RuntimeException('Verschlüsselte Datei konnte nicht geschrieben werden.');
        }
        chmod($tmp, 0600);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Verschlüsselte Datei konnte nicht abgelegt werden.');
        }
        sodium_memzero($plaintext);
    }

    /**
     * Verschlüsselt im Speicher. Ergebnis: Nonce (24 Byte) gefolgt vom Chiffretext, Gegenstück decrypt().
     */
    public static function encrypt(string $plaintext, string $key): string
    {
        self::assertKey($key);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return $nonce . sodium_crypto_secretbox($plaintext, $nonce, $key);
    }

    /**
     * Entschlüsselt den Inhalt einer mit encryptToFile() angelegten Datei.
     */
    public static function decryptFile(string $path, string $key): string
    {
        self::assertKey($key);
        $data = file_get_contents($path);
        if ($data === false) {
            throw new \RuntimeException('Verschlüsselte Datei konnte nicht gelesen werden.');
        }

        return self::decrypt($data, $key);
    }

    public static function decrypt(string $data, string $key): string
    {
        self::assertKey($key);
        $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($data) < $nonceLength + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Verschlüsselte Daten sind unvollständig.');
        }
        $nonce = substr($data, 0, $nonceLength);
        $cipher = substr($data, $nonceLength);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plain === false) {
            throw new \RuntimeException('Entschlüsselung fehlgeschlagen (falscher Schlüssel oder beschädigte Datei).');
        }

        return $plain;
    }

    private static function assertKey(string $key): void
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('Schlüssel hat nicht die erforderliche Länge.');
        }
    }
}
