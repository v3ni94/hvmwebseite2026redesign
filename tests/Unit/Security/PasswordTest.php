<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Security;

use Hvm\Security\Password;
use PHPUnit\Framework\TestCase;

final class PasswordTest extends TestCase
{
    public function testHashAndVerify(): void
    {
        $hash = Password::hash('Lange-Passphrase-fuer-Tests-2026');
        self::assertTrue(Password::verify('Lange-Passphrase-fuer-Tests-2026', $hash));
        self::assertFalse(Password::verify('lange-passphrase-fuer-tests-2026', $hash));
        self::assertFalse(Password::needsRehash($hash));
        if (defined('PASSWORD_ARGON2ID')) {
            self::assertStringStartsWith('$argon2id$', $hash);
        }
    }

    public function testOutdatedHashNeedsRehash(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('PHP ohne Argon2id, bcrypt ist dann aktueller Standard.');
        }
        $bcrypt = password_hash('Lange-Passphrase-fuer-Tests-2026', PASSWORD_BCRYPT);
        self::assertTrue(Password::verify('Lange-Passphrase-fuer-Tests-2026', $bcrypt));
        self::assertTrue(Password::needsRehash($bcrypt));
    }

    public function testPolicy(): void
    {
        self::assertSame([], Password::policyErrors('Kaffeetasse-Regenschirm-41', 'person@example.org'));
        self::assertNotSame([], Password::policyErrors('kurz1!', ''));
        self::assertNotSame([], Password::policyErrors('aaaaaaaaaaaaaaaa', ''));
        self::assertNotSame([], Password::policyErrors('Passwort123456!', ''));
        self::assertNotSame([], Password::policyErrors('xx-person-xx-2026-lang', 'person@example.org'));
        self::assertNotSame([], Password::policyErrors(str_repeat('ab1!', 70), ''));
    }

    public function testOverlongInputIsRejectedWithoutHashing(): void
    {
        $hash = Password::hash('Lange-Passphrase-fuer-Tests-2026');
        self::assertFalse(Password::verify(str_repeat('x', 5000), $hash));
    }

    public function testDummyVerificationRuns(): void
    {
        $start = hrtime(true);
        Password::verifyDummy('irgendetwas');
        self::assertGreaterThan(0, hrtime(true) - $start);
    }

    public function testDummyHashIsPrecomputedAndMatchesCurrentParameters(): void
    {
        $dummy = Password::dummyHash();
        // Gleiche Parameter wie ein echter Hash, sonst unterscheidet sich die Laufzeit
        self::assertFalse(Password::needsRehash($dummy));
        self::assertSame($dummy, Password::dummyHash());
        if (defined('PASSWORD_ARGON2ID')) {
            // Konstante statt Erzeugung beim ersten unbekannten Konto
            self::assertSame(Password::DUMMY_HASH_ARGON2ID, $dummy);
        }
        self::assertFalse(password_verify('', $dummy));
    }

    public function testFirstDummyVerificationCostsLikeRealVerification(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('Zeitvergleich nur mit Argon2id-Konstante aussagekräftig.');
        }
        $real = Password::hash('Lange-Passphrase-fuer-Tests-2026');
        // Zustand wie beim ersten Aufruf nach dem Laden der Klasse
        (new \ReflectionProperty(Password::class, 'dummyHash'))->setValue(null, null);
        $measure = static function (callable $fn): int {
            $start = hrtime(true);
            $fn();

            return hrtime(true) - $start;
        };
        $dummy = $measure(static fn () => Password::verifyDummy('Falsche-Passphrase-2026'));
        $verify = $measure(static fn () => Password::verify('Falsche-Passphrase-2026', $real));
        // Großzügige Grenze gegen Schwankungen: kein zusätzlicher Hash-Aufbau (Faktor 2) beim ersten Aufruf
        self::assertLessThan($verify * 1.8, $dummy);
        self::assertGreaterThan($verify * 0.4, $dummy);
    }
}
