<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Validation;

use DateTimeImmutable;
use DateTimeZone;
use Hvm\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testTextIsTrimmedAndControlCharactersRemoved(): void
    {
        $v = new Validator(['ort' => "  Monheim\x00 am\tRhein \n"]);
        self::assertSame('Monheim am Rhein', $v->text('ort', 'Ort', 100, true));
        self::assertTrue($v->isValid());
    }

    public function testRequiredTextMissing(): void
    {
        $v = new Validator(['ort' => '   ']);
        self::assertNull($v->text('ort', 'Ort', 100, true));
        self::assertSame('Bitte füllen Sie das Feld „Ort“ aus.', $v->errors()['ort']);
    }

    public function testTextTooLong(): void
    {
        $v = new Validator(['ort' => str_repeat('ä', 101)]);
        $v->text('ort', 'Ort', 100);
        self::assertStringContainsString('höchstens 100 Zeichen', $v->errors()['ort']);
        self::assertSame(str_repeat('ä', 101), $v->value('ort'), 'Wert bleibt zum erneuten Befüllen erhalten');
    }

    public function testArraysAndInvalidUtf8AreRejected(): void
    {
        $v = new Validator(['a' => ['x'], 'b' => "\xC3\x28"]);
        $v->text('a', 'A');
        $v->text('b', 'B');
        self::assertArrayHasKey('a', $v->errors());
        self::assertArrayHasKey('b', $v->errors());
    }

    public function testEmail(): void
    {
        $v = new Validator(['ok' => 'name@example.org', 'kaputt' => 'name@', 'kopf' => "a@example.org\r\nBcc: b@example.org"]);
        self::assertSame('name@example.org', $v->email('ok'));
        self::assertNull($v->email('kaputt'));
        self::assertNull($v->email('kopf'));
        self::assertStringContainsString('gültige E-Mail-Adresse', $v->errors()['kaputt']);
        self::assertArrayHasKey('kopf', $v->errors());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function plzFaelle(): iterable
    {
        yield 'gueltig' => ['40789', true];
        yield 'mit Leerzeichen' => [' 40789 ', true];
        yield 'vierstellig' => ['4078', false];
        yield 'sechsstellig' => ['407891', false];
        yield 'Buchstaben' => ['4078a', false];
    }

    #[DataProvider('plzFaelle')]
    public function testPlz(string $eingabe, bool $gueltig): void
    {
        $v = new Validator(['plz' => $eingabe]);
        $v->plz('plz');
        self::assertSame($gueltig, $v->isValid());
    }

    public function testIntegerRange(): void
    {
        $v = new Validator(['a' => '12', 'b' => '10000', 'c' => '1,5', 'd' => '', 'e' => '-1']);
        self::assertSame(12, $v->integer('a', 'A', 0, 9999));
        self::assertNull($v->integer('b', 'B', 0, 9999));
        self::assertNull($v->integer('c', 'C', 0, 9999));
        self::assertSame(0, $v->integer('d', 'D', 0, 9999, false, 0));
        self::assertNull($v->integer('e', 'E', 0, 9999));
        self::assertSame(['b', 'c', 'e'], array_keys($v->errors()));
    }

    public function testChoice(): void
    {
        $v = new Validator(['art' => 'weg', 'rolle' => 'hacker']);
        self::assertSame('weg', $v->choice('art', 'Art', ['weg', 'miet', 'se'], true));
        self::assertNull($v->choice('rolle', 'Rolle', ['eigentuemer']));
        self::assertArrayHasKey('rolle', $v->errors());
    }

    public function testMonthFormats(): void
    {
        $von = new DateTimeImmutable('2025-09-01', new DateTimeZone('UTC'));
        $bis = new DateTimeImmutable('2031-09-01', new DateTimeZone('UTC'));
        $v = new Validator(['a' => '2027-01', 'b' => '03.2027', 'c' => '13.2027', 'd' => '2040-01']);
        self::assertSame('2027-01-01', $v->month('a', 'A', $von, $bis));
        self::assertSame('2027-03-01', $v->month('b', 'B', $von, $bis));
        self::assertNull($v->month('c', 'C', $von, $bis));
        self::assertNull($v->month('d', 'D', $von, $bis));
        self::assertSame(['c', 'd'], array_keys($v->errors()));
    }

    public function testMultilineKeepsLineBreaks(): void
    {
        $v = new Validator(['n' => "Zeile 1\r\nZeile 2\n\n\n\nZeile 3\x07"]);
        self::assertSame("Zeile 1\nZeile 2\n\nZeile 3", $v->multiline('n', 'Nachricht'));
    }

    public function testPhone(): void
    {
        $v = new Validator(['a' => '+49 211 123456', 'b' => '12345', 'c' => '0211 abc']);
        self::assertSame('+49 211 123456', $v->phone('a'));
        self::assertNull($v->phone('b'));
        self::assertNull($v->phone('c'));
    }

    public function testAccepted(): void
    {
        $v = new Validator(['ja' => '1']);
        self::assertTrue($v->accepted('ja', 'Fehlt'));
        self::assertFalse($v->accepted('nein', 'Fehlt'));
        self::assertSame(['nein' => 'Fehlt'], $v->errors());
    }
}
