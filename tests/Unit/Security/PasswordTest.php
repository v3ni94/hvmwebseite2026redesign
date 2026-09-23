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
}
