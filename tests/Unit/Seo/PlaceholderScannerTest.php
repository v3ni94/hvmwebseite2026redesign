<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Seo;

use Hvm\Seo\PlaceholderScanner;
use PHPUnit\Framework\TestCase;

final class PlaceholderScannerTest extends TestCase
{
    private string $verzeichnis;

    protected function setUp(): void
    {
        $this->verzeichnis = sys_get_temp_dir() . '/hvm-placeholder-scanner-' . bin2hex(random_bytes(6));
        mkdir($this->verzeichnis . '/templates', 0775, true);
        mkdir($this->verzeichnis . '/config', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->entfernen($this->verzeichnis);
    }

    private function entfernen(string $pfad): void
    {
        if (!is_dir($pfad)) {
            return;
        }
        foreach (scandir($pfad) ?: [] as $eintrag) {
            if ($eintrag === '.' || $eintrag === '..') {
                continue;
            }
            $ziel = $pfad . '/' . $eintrag;
            is_dir($ziel) ? $this->entfernen($ziel) : unlink($ziel);
        }
        rmdir($pfad);
    }

    public function testFindetPlatzhalterMitKennwort(): void
    {
        file_put_contents(
            $this->verzeichnis . '/templates/start.html.twig',
            "Zeile eins\nDie Notfallnummer ist [Notfallnummer bestätigen].\n"
        );

        $funde = PlaceholderScanner::scan(['templates'], $this->verzeichnis);

        self::assertCount(1, $funde);
        self::assertSame('templates/start.html.twig', $funde[0]['datei']);
        self::assertSame(2, $funde[0]['zeile']);
        self::assertSame('Notfallnummer bestätigen', $funde[0]['text']);
        self::assertFalse($funde[0]['freigegeben']);
    }

    public function testIgnoriertEckigeKlammernOhneKennwort(): void
    {
        file_put_contents(
            $this->verzeichnis . '/templates/beispiel.html.twig',
            "Array-Zugriff wie [0] oder [key] ist kein Platzhalter.\n"
        );

        self::assertSame([], PlaceholderScanner::scan(['templates'], $this->verzeichnis));
    }

    public function testFreigabeUeberDateiUndZeile(): void
    {
        file_put_contents(
            $this->verzeichnis . '/config/unternehmen.php',
            "<?php\n// [USt-IdNr. ergänzen, falls vorhanden]\n"
        );

        $funde = PlaceholderScanner::scan(['config'], $this->verzeichnis, ['config/unternehmen.php:2']);

        self::assertCount(1, $funde);
        self::assertTrue($funde[0]['freigegeben']);
    }

    public function testFreigabeUeberTeilstringDesTexts(): void
    {
        file_put_contents(
            $this->verzeichnis . '/config/standorte.php',
            "<?php\n// [Portal-Adresse bestätigen]\n"
        );

        $funde = PlaceholderScanner::scan(['config'], $this->verzeichnis, ['Portal-Adresse']);

        self::assertTrue($funde[0]['freigegeben']);
    }

    public function testUeberspringtBinaerdateien(): void
    {
        file_put_contents($this->verzeichnis . '/templates/logo.png', "\x89PNG [bestätigen]");

        self::assertSame([], PlaceholderScanner::scan(['templates'], $this->verzeichnis));
    }

    public function testMehrereFundeSindNachDateiUndZeileSortiert(): void
    {
        file_put_contents($this->verzeichnis . '/templates/b.html.twig', "[festlegen]\n");
        file_put_contents($this->verzeichnis . '/templates/a.html.twig', "eins\n[klären]\n");

        $funde = PlaceholderScanner::scan(['templates'], $this->verzeichnis);

        self::assertSame('templates/a.html.twig', $funde[0]['datei']);
        self::assertSame('templates/b.html.twig', $funde[1]['datei']);
    }
}
