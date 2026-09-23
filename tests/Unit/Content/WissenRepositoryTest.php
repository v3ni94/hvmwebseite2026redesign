<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Content;

use Hvm\Content\Article;
use Hvm\Content\WissenRepository;
use Hvm\Support\Config;
use Hvm\Support\Log;
use PHPUnit\Framework\TestCase;

final class WissenRepositoryTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/wissen';

    private string $temp;

    protected function setUp(): void
    {
        $this->temp = sys_get_temp_dir() . '/hvm-wissen-test-' . bin2hex(random_bytes(6));
        mkdir($this->temp, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temp . '/*') ?: [] as $datei) {
            unlink($datei);
        }
        rmdir($this->temp);
    }

    private function repository(string $verzeichnis, string $env = 'development', bool $showDrafts = false): WissenRepository
    {
        $config = new Config(['app' => ['env' => $env, 'show_drafts' => $showDrafts]]);
        $log = new Log(sys_get_temp_dir(), 'hvm-test.log');

        return new WissenRepository($config, $log, $verzeichnis);
    }

    public function testValiderArtikelWirdGelesenUndGerendert(): void
    {
        $repo = $this->repository(self::FIXTURES);
        $artikel = $repo->findBySlug('beispielartikel');

        self::assertNotNull($artikel);
        self::assertSame('Testartikel für die Wissensdatenbank', $artikel->titel);
        self::assertSame('weg-beirat', $artikel->zielgruppe);
        self::assertSame('2026-01-15', $artikel->stand);
        self::assertTrue($artikel->freigegeben);
        self::assertFalse($artikel->istEntwurf);
        self::assertCount(1, $artikel->faq);
        self::assertSame('Ist dieser Artikel echt?', $artikel->faq[0]['frage']);
        // Vier H2-Überschriften => Inhaltsverzeichnis wird ab mehr als drei Abschnitten angezeigt.
        self::assertCount(4, $artikel->ueberschriften);
        self::assertSame('erster-testabschnitt', $artikel->ueberschriften[0]['id']);
    }

    public function testEingebettetesHtmlWirdEscaped(): void
    {
        $repo = $this->repository(self::FIXTURES);
        $artikel = $repo->findBySlug('beispielartikel');

        self::assertNotNull($artikel);
        self::assertStringNotContainsString('<script>', $artikel->html);
        self::assertStringContainsString('&lt;script&gt;', $artikel->html);
    }

    public function testNichtFreigegebenerArtikelIstStandardmaessigNichtSichtbar(): void
    {
        file_put_contents($this->temp . '/entwurf.md', $this->frontmatter(['freigabe' => 'nein']) . "\nText.\n");
        $repo = $this->repository($this->temp, 'development', false);

        self::assertNull($repo->findBySlug('entwurf'));
        self::assertSame([], $repo->veroeffentlichte());
    }

    public function testEntwurfErscheintNurAusserhalbDerProduktionMitShowDrafts(): void
    {
        file_put_contents($this->temp . '/entwurf.md', $this->frontmatter(['freigabe' => 'nein']) . "\nText.\n");

        $repoStaging = $this->repository($this->temp, 'staging', true);
        $entwurf = $repoStaging->findBySlug('entwurf');
        self::assertNotNull($entwurf);
        self::assertTrue($entwurf->istEntwurf);

        // Produktion erzwingt SHOW_DRAFTS=false unabhängig vom Konfigurationswert.
        $repoProduktion = $this->repository($this->temp, 'production', true);
        self::assertNull($repoProduktion->findBySlug('entwurf'));
    }

    public function testFehlendesPflichtfeldWirdAlsFehlerErfasstUndArtikelUebersprungen(): void
    {
        file_put_contents($this->temp . '/ungueltig.md', "---\ntitel: \"Ohne Zielgruppe\"\nslug: ungueltig\n---\n\nText.\n");
        $repo = $this->repository($this->temp);

        self::assertNull($repo->findBySlug('ungueltig'));
        $fehler = $repo->fehlermeldungen();
        self::assertArrayHasKey('ungueltig.md', $fehler);
        self::assertNotEmpty($fehler['ungueltig.md']);
    }

    public function testAbweichenderSlugIstEinValidierungsfehler(): void
    {
        file_put_contents(
            $this->temp . '/falscher-name.md',
            $this->frontmatter(['slug' => 'anderer-slug']) . "\nText.\n"
        );
        $repo = $this->repository($this->temp);

        self::assertNull($repo->findBySlug('falscher-name'));
        self::assertArrayHasKey('falscher-name.md', $repo->fehlermeldungen());
    }

    public function testMehrAlsFuenfFaqEintraegeSindEinValidierungsfehler(): void
    {
        $faq = implode("\n", array_map(
            static fn (int $i): string => sprintf("  - frage: \"Frage %d?\"\n    antwort: \"Antwort %d\"", $i, $i),
            range(1, 6)
        ));
        file_put_contents(
            $this->temp . '/zuviele-faq.md',
            $this->frontmatter(['slug' => 'zuviele-faq'], "faq:\n" . $faq) . "\nText.\n"
        );
        $repo = $this->repository($this->temp);

        self::assertNull($repo->findBySlug('zuviele-faq'));
        self::assertArrayHasKey('zuviele-faq.md', $repo->fehlermeldungen());
    }

    public function testVerwandteArtikelSindDerselbenZielgruppeUndSchliessenSichSelbstAus(): void
    {
        file_put_contents($this->temp . '/erster.md', $this->frontmatter(['slug' => 'erster']) . "\nText.\n");
        file_put_contents($this->temp . '/zweiter.md', $this->frontmatter(['slug' => 'zweiter']) . "\nText.\n");
        file_put_contents($this->temp . '/andere-zielgruppe.md', $this->frontmatter([
            'slug' => 'andere-zielgruppe',
            'zielgruppe' => 'mieter',
        ]) . "\nText.\n");
        $repo = $this->repository($this->temp);

        $erster = $repo->findBySlug('erster');
        self::assertNotNull($erster);
        $verwandte = $repo->verwandte($erster);

        self::assertCount(1, $verwandte);
        self::assertSame('zweiter', $verwandte[0]->slug);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function frontmatter(array $overrides = [], string $zusatz = ''): string
    {
        $felder = array_merge([
            'titel' => 'Testartikel',
            'slug' => 'entwurf',
            'zielgruppe' => 'weg-beirat',
            'beschreibung' => 'Fiktiver Testinhalt.',
            'stand' => '2026-01-01',
            'autor' => 'Hausverwaltung Müller GmbH',
            'freigabe' => 'ja',
            'leistung' => '/weg-verwaltung/',
            'cta' => 'angebot',
        ], $overrides);

        $zeilen = ['---'];
        foreach ($felder as $schluessel => $wert) {
            $zeilen[] = sprintf('%s: "%s"', $schluessel, $wert);
        }
        if ($zusatz !== '') {
            $zeilen[] = $zusatz;
        }
        $zeilen[] = '---';

        return implode("\n", $zeilen) . "\n";
    }
}
