<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Content;

use Hvm\Content\FaqRepository;
use Hvm\Support\Config;
use Hvm\Support\Log;
use PHPUnit\Framework\TestCase;

final class FaqRepositoryTest extends TestCase
{
    private string $temp;

    protected function setUp(): void
    {
        $this->temp = sys_get_temp_dir() . '/hvm-faq-test-' . bin2hex(random_bytes(6));
        mkdir($this->temp, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temp . '/*') ?: [] as $datei) {
            unlink($datei);
        }
        rmdir($this->temp);
    }

    private function repository(string $env = 'development', bool $showDrafts = false): FaqRepository
    {
        $config = new Config(['app' => ['env' => $env, 'show_drafts' => $showDrafts]]);

        return new FaqRepository($config, new Log(sys_get_temp_dir(), 'hvm-test.log'), $this->temp);
    }

    private function schreibeDatei(string $name, string $yaml): void
    {
        file_put_contents($this->temp . '/' . $name, $yaml);
    }

    public function testVeroeffentlichteFrageWirdGelesen(): void
    {
        $this->schreibeDatei('kosten-vertrag.yaml', <<<YAML
            zielgruppe: kosten-vertrag
            titel: "Kosten und Vertrag"
            fragen:
              - frage: "Gibt es eine Aufnahmegebühr?"
                antwort: "Nein, es fällt keine Aufnahmegebühr an."
                freigabe: ja
            YAML);

        $gruppe = $this->repository()->gruppeFuer('kosten-vertrag');

        self::assertNotNull($gruppe);
        self::assertSame('Kosten und Vertrag', $gruppe->titel);
        self::assertCount(1, $gruppe->fragen);
        self::assertCount(1, $gruppe->veroeffentlichte());
        self::assertTrue($gruppe->fragen[0]->freigegeben);
    }

    public function testNichtFreigegebeneFrageIstStandardmaessigNichtSichtbar(): void
    {
        $this->schreibeDatei('mieter.yaml', <<<YAML
            zielgruppe: mieter
            titel: "Mieter"
            fragen:
              - frage: "Entwurfsfrage?"
                antwort: "Entwurfsantwort."
                freigabe: nein
            YAML);

        self::assertNull($this->repository()->gruppeFuer('mieter'));
    }

    public function testEntwurfsfrageErscheintNurAusserhalbDerProduktionMitShowDrafts(): void
    {
        $this->schreibeDatei('mieter.yaml', <<<YAML
            zielgruppe: mieter
            titel: "Mieter"
            fragen:
              - frage: "Entwurfsfrage?"
                antwort: "Entwurfsantwort."
                freigabe: nein
            YAML);

        $staging = $this->repository('staging', true)->gruppeFuer('mieter');
        self::assertNotNull($staging);
        self::assertFalse($staging->fragen[0]->freigegeben);
        self::assertCount(0, $staging->veroeffentlichte());

        self::assertNull($this->repository('production', true)->gruppeFuer('mieter'));
    }

    public function testFehlendesPflichtfeldWirdAlsFehlerErfasst(): void
    {
        $this->schreibeDatei('ungueltig.yaml', <<<YAML
            titel: "Ohne Zielgruppe"
            fragen: []
            YAML);

        $repo = $this->repository();
        self::assertNull($repo->gruppeFuer('mieter'));
        self::assertArrayHasKey('ungueltig.yaml', $repo->fehlermeldungen());
    }

    public function testUngueltigeZielgruppeWirdAlsFehlerErfasst(): void
    {
        $this->schreibeDatei('unbekannt.yaml', <<<YAML
            zielgruppe: unbekannt
            titel: "Unbekannt"
            fragen:
              - frage: "Frage?"
                antwort: "Antwort."
                freigabe: ja
            YAML);

        $repo = $this->repository();
        self::assertNull($repo->gruppeFuer('unbekannt'));
        self::assertArrayHasKey('unbekannt.yaml', $repo->fehlermeldungen());
    }
}
