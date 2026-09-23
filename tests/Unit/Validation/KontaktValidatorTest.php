<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Validation;

use Hvm\Validation\KontaktValidator;
use PHPUnit\Framework\TestCase;

final class KontaktValidatorTest extends TestCase
{
    private function valid(): array
    {
        return [
            'anliegen' => 'allgemein',
            'name' => 'Erika Beispiel',
            'email' => 'erika.beispiel@example.org',
            'telefon' => '0211 123456',
            'nachricht' => 'Fiktive Testnachricht.',
            'datenschutz' => '1',
        ];
    }

    public function testValidInputPasses(): void
    {
        $result = (new KontaktValidator())->validate($this->valid());

        self::assertTrue($result['valid']);
        self::assertSame([], $result['errors']);
        self::assertSame('allgemein', $result['data']['anliegen']);
        self::assertSame('Erika Beispiel', $result['data']['name']);
        self::assertSame('erika.beispiel@example.org', $result['data']['email']);
        self::assertSame('Fiktive Testnachricht.', $result['data']['nachricht']);
    }

    public function testMissingAnliegenFails(): void
    {
        $input = $this->valid();
        unset($input['anliegen']);
        $result = (new KontaktValidator())->validate($input);

        self::assertFalse($result['valid']);
        self::assertArrayHasKey('anliegen', $result['errors']);
    }

    public function testUnknownAnliegenFails(): void
    {
        $input = $this->valid();
        $input['anliegen'] = 'sonstiges';
        $result = (new KontaktValidator())->validate($input);

        self::assertFalse($result['valid']);
        self::assertArrayHasKey('anliegen', $result['errors']);
    }

    public function testNameIsOptional(): void
    {
        $input = $this->valid();
        unset($input['name']);
        $result = (new KontaktValidator())->validate($input);

        self::assertTrue($result['valid']);
        self::assertNull($result['data']['name']);
    }

    public function testInvalidEmailFails(): void
    {
        $input = $this->valid();
        $input['email'] = 'kaputt';
        $result = (new KontaktValidator())->validate($input);

        self::assertFalse($result['valid']);
        self::assertArrayHasKey('email', $result['errors']);
    }

    public function testMissingNachrichtFails(): void
    {
        $input = $this->valid();
        unset($input['nachricht']);
        $result = (new KontaktValidator())->validate($input);

        self::assertFalse($result['valid']);
        self::assertArrayHasKey('nachricht', $result['errors']);
    }

    public function testMissingConsentFails(): void
    {
        $input = $this->valid();
        unset($input['datenschutz']);
        $result = (new KontaktValidator())->validate($input);

        self::assertFalse($result['valid']);
        self::assertArrayHasKey('datenschutz', $result['errors']);
    }

    public function testTelefonIsOptionalButValidatedWhenGiven(): void
    {
        $input = $this->valid();
        $input['telefon'] = 'abc';
        $result = (new KontaktValidator())->validate($input);

        self::assertFalse($result['valid']);
        self::assertArrayHasKey('telefon', $result['errors']);
    }

    public function testErrorsAreOrderedByFieldOrder(): void
    {
        $input = [
            'anliegen' => '',
            'email' => 'kaputt',
            'nachricht' => '',
            'datenschutz' => '',
        ];
        $result = (new KontaktValidator())->validate($input);

        self::assertSame(['anliegen', 'email', 'nachricht', 'datenschutz'], array_keys($result['errors']));
    }

    public function testFieldIdMatchesDefaultMacroId(): void
    {
        self::assertSame('feld-email', KontaktValidator::fieldId('email'));
    }
}
