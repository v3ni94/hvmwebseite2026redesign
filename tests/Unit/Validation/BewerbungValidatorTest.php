<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Validation;

use Hvm\Validation\BewerbungValidator;
use PHPUnit\Framework\TestCase;

final class BewerbungValidatorTest extends TestCase
{
    private function valid(): array
    {
        return [
            'stelle' => 'Sachbearbeitung WEG-Verwaltung',
            'name' => 'Max Mustermann',
            'email' => 'max.mustermann@example.org',
            'telefon' => '0211 123456',
            'nachricht' => 'Fiktives Anschreiben.',
            'einwilligung' => '1',
        ];
    }

    public function testValidInputPasses(): void
    {
        $result = (new BewerbungValidator())->validate($this->valid());

        self::assertTrue($result['valid']);
        self::assertSame('Max Mustermann', $result['data']['name']);
        self::assertSame('max.mustermann@example.org', $result['data']['email']);
        self::assertSame('Sachbearbeitung WEG-Verwaltung', $result['data']['stelle']);
    }

    public function testStelleIsFreetextWhenNoStellenConfigured(): void
    {
        $input = $this->valid();
        $input['stelle'] = 'Eine ganz neue Wunschstelle';
        $result = (new BewerbungValidator())->validate($input, []);

        self::assertTrue($result['valid']);
        self::assertSame('Eine ganz neue Wunschstelle', $result['data']['stelle']);
    }

    public function testStelleIsChoiceWhenStellenConfigured(): void
    {
        $stellen = [['slug' => 'sachbearbeitung-weg', 'titel' => 'Sachbearbeitung WEG-Verwaltung']];
        $input = $this->valid();
        $input['stelle'] = 'sachbearbeitung-weg';
        $result = (new BewerbungValidator())->validate($input, $stellen);

        self::assertTrue($result['valid']);
        self::assertSame('sachbearbeitung-weg', $result['data']['stelle']);
    }

    public function testUnknownStelleIsRejectedWhenStellenConfigured(): void
    {
        $stellen = [['slug' => 'sachbearbeitung-weg', 'titel' => 'Sachbearbeitung WEG-Verwaltung']];
        $input = $this->valid();
        $input['stelle'] = 'erfundene-stelle';
        $result = (new BewerbungValidator())->validate($input, $stellen);

        self::assertFalse($result['valid']);
        self::assertArrayHasKey('stelle', $result['errors']);
    }

    public function testStelleIsOptional(): void
    {
        $input = $this->valid();
        unset($input['stelle']);
        $result = (new BewerbungValidator())->validate($input);

        self::assertTrue($result['valid']);
        self::assertNull($result['data']['stelle']);
    }

    public function testMissingNameFails(): void
    {
        $input = $this->valid();
        unset($input['name']);
        $result = (new BewerbungValidator())->validate($input);

        self::assertFalse($result['valid']);
        self::assertArrayHasKey('name', $result['errors']);
    }

    public function testInvalidEmailFails(): void
    {
        $input = $this->valid();
        $input['email'] = 'kaputt';
        $result = (new BewerbungValidator())->validate($input);

        self::assertFalse($result['valid']);
        self::assertArrayHasKey('email', $result['errors']);
    }

    public function testNachrichtIsOptional(): void
    {
        $input = $this->valid();
        unset($input['nachricht']);
        $result = (new BewerbungValidator())->validate($input);

        self::assertTrue($result['valid']);
        self::assertNull($result['data']['nachricht']);
    }

    public function testMissingEinwilligungFails(): void
    {
        $input = $this->valid();
        unset($input['einwilligung']);
        $result = (new BewerbungValidator())->validate($input);

        self::assertFalse($result['valid']);
        self::assertArrayHasKey('einwilligung', $result['errors']);
    }

    public function testDataNeverContainsDateiKey(): void
    {
        $result = (new BewerbungValidator())->validate($this->valid());

        self::assertArrayNotHasKey('datei', $result['data']);
    }
}
