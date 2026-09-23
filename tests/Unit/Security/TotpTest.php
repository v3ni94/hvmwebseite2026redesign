<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Security;

use Hvm\Security\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    private const SEED_SHA1 = '12345678901234567890';
    private const SEED_SHA256 = '12345678901234567890123456789012';
    private const SEED_SHA512 = '1234567890123456789012345678901234567890123456789012345678901234';

    /**
     * RFC 6238 Anhang B (8 Ziffern, 30 Sekunden).
     *
     * @return iterable<string, array{int, string, string}>
     */
    public static function rfcVectors(): iterable
    {
        $rows = [
            [59, '94287082', '46119246', '90693936'],
            [1111111109, '07081804', '68084774', '25091201'],
            [1111111111, '14050471', '67062674', '99943326'],
            [1234567890, '89005924', '91819424', '93441116'],
            [2000000000, '69279037', '90698825', '38618901'],
            [20000000000, '65353130', '77737706', '47863826'],
        ];
        foreach ($rows as [$time, $sha1, $sha256, $sha512]) {
            yield 'SHA1 T=' . $time => [$time, 'sha1', $sha1];
            yield 'SHA256 T=' . $time => [$time, 'sha256', $sha256];
            yield 'SHA512 T=' . $time => [$time, 'sha512', $sha512];
        }
    }

    #[DataProvider('rfcVectors')]
    public function testRfc6238Vectors(int $time, string $algo, string $expected): void
    {
        $seed = match ($algo) {
            'sha1' => self::SEED_SHA1,
            'sha256' => self::SEED_SHA256,
            default => self::SEED_SHA512,
        };
        self::assertSame($expected, Totp::at($seed, $time, 8, $algo));
    }

    public function testRfc4226HotpVectors(): void
    {
        // RFC 4226 Anhang D, 6 Ziffern
        $expected = ['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'];
        foreach ($expected as $counter => $code) {
            self::assertSame($code, Totp::hotp(self::SEED_SHA1, $counter));
        }
    }

    public function testBase32RoundTripAndRfc4648Vectors(): void
    {
        self::assertSame('MZXW6YTBOI', Totp::base32Encode('foobar'));
        self::assertSame('foobar', Totp::base32Decode('mzxw6ytboi======'));
        self::assertSame('', Totp::base32Decode('ungültig!'));
        $secret = Totp::generateSecret();
        self::assertSame(32, strlen($secret));
        self::assertSame(20, strlen(Totp::base32Decode($secret)));
    }

    public function testVerifyAcceptsOneStepToleranceAndReturnsStep(): void
    {
        $secret = Totp::base32Encode(self::SEED_SHA1);
        $now = 1_800_000_015;
        $step = intdiv($now, 30);
        self::assertSame($step, Totp::verify($secret, Totp::code($secret, $now), $now));
        self::assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $now - 30), $now));
        self::assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $now + 30), $now));
        self::assertNull(Totp::verify($secret, Totp::code($secret, $now - 60), $now));
        self::assertNull(Totp::verify($secret, Totp::code($secret, $now + 60), $now));
    }

    public function testVerifyRejectsReuseOfSameOrOlderStep(): void
    {
        $secret = Totp::base32Encode(self::SEED_SHA1);
        $now = 1_800_000_015;
        $code = Totp::code($secret, $now);
        $step = Totp::verify($secret, $code, $now);
        self::assertNotNull($step);
        self::assertNull(Totp::verify($secret, $code, $now, $step));
        self::assertNull(Totp::verify($secret, Totp::code($secret, $now - 30), $now, $step));
        self::assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $now + 30), $now + 30, $step));
    }

    public function testVerifyRejectsMalformedCodes(): void
    {
        $secret = Totp::base32Encode(self::SEED_SHA1);
        foreach (['', '12345', '1234567', 'abcdef', '12 34 5x'] as $code) {
            self::assertNull(Totp::verify($secret, $code, 1_800_000_000), $code);
        }
        // Leerzeichen aus der App-Darstellung werden toleriert
        $code = Totp::code($secret, 1_800_000_000);
        self::assertNotNull(Totp::verify($secret, substr($code, 0, 3) . ' ' . substr($code, 3), 1_800_000_000));
    }

    public function testUriContainsIssuerAccountAndParameters(): void
    {
        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'person@example.org', 'Hausverwaltung Müller');
        self::assertStringStartsWith('otpauth://totp/Hausverwaltung%20M%C3%BCller:person%40example.org?secret=JBSWY3DPEHPK3PXP', $uri);
        self::assertStringContainsString('&digits=6&period=30', $uri);
    }
}
