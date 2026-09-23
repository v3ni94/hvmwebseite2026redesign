<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Security;

use Hvm\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    private function key(): string
    {
        return random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function testEncryptToFileAndDecryptFileRoundTrip(): void
    {
        $key = $this->key();
        $path = tempnam(sys_get_temp_dir(), 'hvm-crypto-');
        self::assertIsString($path);

        try {
            Crypto::encryptToFile('%PDF-1.4 Testinhalt', $path, $key);
            $onDisk = (string) file_get_contents($path);
            self::assertStringNotContainsString('Testinhalt', $onDisk, 'die Datei liegt nicht im Klartext');

            $plain = Crypto::decryptFile($path, $key);
            self::assertSame('%PDF-1.4 Testinhalt', $plain);
        } finally {
            @unlink($path);
        }
    }

    public function testDecryptWithWrongKeyFails(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hvm-crypto-');
        self::assertIsString($path);

        try {
            Crypto::encryptToFile('geheim', $path, $this->key());

            $this->expectException(\RuntimeException::class);
            Crypto::decryptFile($path, $this->key());
        } finally {
            @unlink($path);
        }
    }

    public function testDecryptOfTruncatedDataFails(): void
    {
        $this->expectException(\RuntimeException::class);
        Crypto::decrypt('zu kurz', $this->key());
    }

    public function testKeyOfWrongLengthIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Crypto::decrypt(str_repeat('a', 100), 'zu kurzer schluessel');
    }

    public function testEachEncryptionUsesAFreshNonce(): void
    {
        $key = $this->key();
        $path1 = tempnam(sys_get_temp_dir(), 'hvm-crypto-');
        $path2 = tempnam(sys_get_temp_dir(), 'hvm-crypto-');
        self::assertIsString($path1);
        self::assertIsString($path2);

        try {
            Crypto::encryptToFile('gleicher inhalt', $path1, $key);
            Crypto::encryptToFile('gleicher inhalt', $path2, $key);
            self::assertNotSame(file_get_contents($path1), file_get_contents($path2));
        } finally {
            @unlink($path1);
            @unlink($path2);
        }
    }
}
