<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Validation;

use DateTimeImmutable;
use DateTimeZone;
use Hvm\Validation\AngebotValidator;
use PHPUnit\Framework\TestCase;

final class AngebotValidatorTest extends TestCase
{
    private function jetzt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-23 10:00:00', new DateTimeZone('UTC'));
    }

    /**
     * @return array<string, string>
     */
    private function minimal(): array
    {
        return [
            'art' => 'weg',
            'plz' => '40789',
            'ort' => 'Monheim am Rhein',
            'wohneinheiten' => '12',
            'nachname' => 'Beispiel',
            'email' => 'test@example.org',
            'datenschutz' => '1',
        ];
    }

    public function testMinimalInputIsValid(): void
    {
        $result = (new AngebotValidator())->validate($this->minimal(), $this->jetzt());
        self::assertTrue($result['valid'], json_encode($result['errors'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame('weg', $result['data']['management_form']);
        self::assertSame(12, $result['data']['units_residential']);
        self::assertSame(0, $result['data']['units_commercial']);
        self::assertNull($result['data']['contact_phone']);
        self::assertNull($result['data']['has_current_manager']);
    }

    public function testEmptyInputReportsRequiredFieldsInFormOrder(): void
    {
        $result = (new AngebotValidator())->validate([], $this->jetzt());
        self::assertFalse($result['valid']);
        self::assertSame(['art', 'plz', 'ort', 'wohneinheiten', 'nachname', 'email', 'datenschutz'], array_keys($result['errors']));
    }

    public function testCommercialUnitsAloneAreEnough(): void
    {
        $input = ['wohneinheiten' => '0', 'gewerbeeinheiten' => '2'] + $this->minimal();
        self::assertTrue((new AngebotValidator())->validate($input, $this->jetzt())['valid']);
    }

    public function testParkingAloneIsNotEnough(): void
    {
        $input = ['wohneinheiten' => '0', 'stellplaetze' => '5'] + $this->minimal();
        $result = (new AngebotValidator())->validate($input, $this->jetzt());
        self::assertSame('Bitte geben Sie mindestens eine Wohn- oder Gewerbeeinheit an.', $result['errors']['wohneinheiten']);
    }

    public function testFirstNameAloneIsEnough(): void
    {
        $input = $this->minimal();
        unset($input['nachname']);
        $input['vorname'] = 'Erika';
        self::assertTrue((new AngebotValidator())->validate($input, $this->jetzt())['valid']);
    }

    public function testYearOfConstructionBounds(): void
    {
        $validator = new AngebotValidator();
        self::assertTrue($validator->validate(['baujahr' => '1800'] + $this->minimal(), $this->jetzt())['valid']);
        self::assertTrue($validator->validate(['baujahr' => '2031'] + $this->minimal(), $this->jetzt())['valid']);
        self::assertArrayHasKey('baujahr', $validator->validate(['baujahr' => '1799'] + $this->minimal(), $this->jetzt())['errors']);
        self::assertArrayHasKey('baujahr', $validator->validate(['baujahr' => '2032'] + $this->minimal(), $this->jetzt())['errors']);
    }

    public function testUnitsUpperBound(): void
    {
        $result = (new AngebotValidator())->validate(['wohneinheiten' => '10000'] + $this->minimal(), $this->jetzt());
        self::assertStringContainsString('zwischen 0 und 9999', $result['errors']['wohneinheiten']);
    }

    public function testOptionalFieldsAreNormalized(): void
    {
        $input = [
            'beginn' => '2027-01',
            'aktueller_verwalter' => 'ja',
            'anrede' => 'frau',
            'rolle' => 'beirat',
            'telefon' => '0211 123456',
            'nachricht' => "Hallo\r\nWelt",
        ] + $this->minimal();
        $result = (new AngebotValidator())->validate($input, $this->jetzt());
        self::assertTrue($result['valid']);
        self::assertSame('2027-01-01', $result['data']['management_start']);
        self::assertTrue($result['data']['has_current_manager']);
        self::assertSame("Hallo\nWelt", $result['data']['message']);
        self::assertSame('2027-01', $result['form']['beginn']);
    }

    public function testUnknownChoicesAreRejected(): void
    {
        $result = (new AngebotValidator())->validate(['art' => 'gewerbe', 'rolle' => 'admin', 'anrede' => 'x'] + $this->minimal(), $this->jetzt());
        self::assertArrayHasKey('art', $result['errors']);
        self::assertArrayHasKey('rolle', $result['errors']);
        self::assertArrayHasKey('anrede', $result['errors']);
    }

    public function testMessageLengthLimit(): void
    {
        $result = (new AngebotValidator())->validate(['nachricht' => str_repeat('a', 3001)] + $this->minimal(), $this->jetzt());
        self::assertArrayHasKey('nachricht', $result['errors']);
    }

    public function testFieldIdsForErrorSummary(): void
    {
        self::assertSame('feld-art-weg', AngebotValidator::fieldId('art'));
        self::assertSame('feld-plz', AngebotValidator::fieldId('plz'));
    }
}
