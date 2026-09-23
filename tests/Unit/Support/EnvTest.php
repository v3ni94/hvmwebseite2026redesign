<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Support;

use Hvm\Support\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    protected function tearDown(): void
    {
        Env::reset();
    }

    public function testParsesTypicalDotenvSyntax(): void
    {
        $content = <<<'ENV'
            # Kommentar
            APP_ENV=development
            export APP_URL=http://127.0.0.1:8081
            EMPTY=
            SPACED = wert mit leerzeichen   # Kommentar am Ende
            DOUBLE="Zeile\nZwei \"zitiert\""
            SINGLE='roh $HOME \n'
            HASH_IN_QUOTES="a # b"
            REF="${APP_ENV}-kopie"
            UMLAUT=Müller
            ungueltig-name=x
            OHNE_GLEICHHEITSZEICHEN
            ENV;

        $vars = Env::parse($content);

        self::assertSame('development', $vars['APP_ENV']);
        self::assertSame('http://127.0.0.1:8081', $vars['APP_URL']);
        self::assertSame('', $vars['EMPTY']);
        self::assertSame('wert mit leerzeichen', $vars['SPACED']);
        self::assertSame("Zeile\nZwei \"zitiert\"", $vars['DOUBLE']);
        self::assertSame('roh $HOME \n', $vars['SINGLE']);
        self::assertSame('a # b', $vars['HASH_IN_QUOTES']);
        self::assertSame('development-kopie', $vars['REF']);
        self::assertSame('Müller', $vars['UMLAUT']);
        self::assertArrayNotHasKey('ungueltig-name', $vars);
        self::assertArrayNotHasKey('OHNE_GLEICHHEITSZEICHEN', $vars);
    }

    public function testWindowsLineEndingsAndBom(): void
    {
        $vars = Env::parse("\u{FEFF}A=1\r\nB=2\r\n");
        self::assertSame(['A' => '1', 'B' => '2'], $vars);
    }

    public function testTypedAccessors(): void
    {
        Env::set('HVM_TEST_BOOL', 'true');
        Env::set('HVM_TEST_BOOL_OFF', '0');
        Env::set('HVM_TEST_INT', '1800');
        Env::set('HVM_TEST_LIST', '10.0.0.0/8, 172.16.0.0/12,,');

        self::assertTrue(Env::bool('HVM_TEST_BOOL'));
        self::assertFalse(Env::bool('HVM_TEST_BOOL_OFF', true));
        self::assertTrue(Env::bool('HVM_TEST_FEHLT', true));
        self::assertSame(1800, Env::int('HVM_TEST_INT'));
        self::assertNull(Env::int('HVM_TEST_FEHLT'));
        self::assertSame(['10.0.0.0/8', '172.16.0.0/12'], Env::list('HVM_TEST_LIST'));
        self::assertSame('standard', Env::string('HVM_TEST_FEHLT', 'standard'));
    }

    public function testLoadReadsFileAndOverridesWin(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env');
        self::assertIsString($file);
        file_put_contents($file, "HVM_TEST_DATEI=aus_datei\n");
        try {
            Env::load($file);
            self::assertSame('aus_datei', Env::get('HVM_TEST_DATEI'));
            Env::set('HVM_TEST_DATEI', 'ueberschrieben');
            self::assertSame('ueberschrieben', Env::get('HVM_TEST_DATEI'));
        } finally {
            unlink($file);
        }
    }

    public function testMissingFileIsIgnored(): void
    {
        Env::load('/pfad/existiert/nicht/.env');
        self::assertNull(Env::get('HVM_TEST_NICHT_GESETZT'));
    }
}
