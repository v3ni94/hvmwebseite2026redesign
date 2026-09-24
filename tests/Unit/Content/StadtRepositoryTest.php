<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Content;

use Hvm\Content\StadtRepository;
use Hvm\Content\StadtSitemapProvider;
use Hvm\Support\Config;
use Hvm\Support\Log;
use PHPUnit\Framework\TestCase;

final class StadtRepositoryTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/staedte';

    private function repository(string $env = 'production', bool $entwuerfe = false, ?string $verzeichnis = self::FIXTURES): StadtRepository
    {
        $config = Config::fromDirectory(dirname(__DIR__, 3) . '/config');
        $config->set('app.env', $env);
        $config->set('app.show_drafts', $entwuerfe);

        return new StadtRepository($config, new Log(sys_get_temp_dir(), 'hvm-test.log'), $verzeichnis);
    }

    public function testKonfigurationEnthaelt42StaedteMitLueckenloserPlzAbdeckung(): void
    {
        $staedte = $this->repository()->staedte();

        self::assertCount(42, $staedte);
        self::assertSame('00001', $staedte[0]->plzVon);
        self::assertSame('99999', $staedte[41]->plzBis);
        for ($i = 1; $i < count($staedte); $i++) {
            self::assertSame((int) $staedte[$i - 1]->plzBis + 1, (int) $staedte[$i]->plzVon, $staedte[$i]->slug);
        }
        $hauptsitz = array_values(array_filter($staedte, static fn ($s): bool => $s->hauptsitz));
        self::assertCount(1, $hauptsitz);
        self::assertSame('monheim-am-rhein', $hauptsitz[0]->slug);
    }

    public function testIndexierbarNurHauptsitzUndStaedteMitBestand(): void
    {
        $indexierbar = [];
        foreach ($this->repository()->staedte() as $stadt) {
            if ($stadt->indexierbar) {
                $indexierbar[] = $stadt->slug;
            }
            self::assertSame($stadt->hauptsitz || $stadt->bestandOrte !== [], $stadt->indexierbar, $stadt->slug);
        }
        sort($indexierbar);

        self::assertSame(['aachen', 'berlin', 'erkelenz', 'essen', 'koeln', 'monheim-am-rhein', 'ulm'], $indexierbar);
    }

    public function testKartenpositionEntsprichtDerProjektionDesUmrisses(): void
    {
        $repo = $this->repository();

        self::assertSame([50.3, 271.5], $repo->stadt('monheim-am-rhein')?->karte());
        self::assertSame([251.6, 467.7], $repo->stadt('muenchen')?->karte());
        foreach ($repo->staedte() as $stadt) {
            [$x, $y] = $stadt->karte();
            self::assertTrue($x > 0 && $x < 400 && $y > 0 && $y < 520, $stadt->slug);
        }
    }

    public function testUngueltigeDateienWerdenGemeldetUndUebersprungen(): void
    {
        $repo = $this->repository('staging', true);
        $fehler = $repo->fehlermeldungen();

        self::assertArrayHasKey('hamburg.md', $fehler);
        self::assertStringContainsString('"stadt"', implode(' ', $fehler['hamburg.md']));
        self::assertArrayHasKey('atlantis.md', $fehler);
        self::assertNull($repo->seite('hamburg'));
        self::assertNull($repo->seite('atlantis'));
    }

    public function testFreigabelogikWieWissensartikel(): void
    {
        self::assertNotNull($this->repository('production')->seite('koeln'));
        self::assertNull($this->repository('production', true)->seite('berlin'), 'Produktion zeigt nie Entwürfe');
        self::assertNull($this->repository('staging', false)->seite('berlin'));
        self::assertNotNull($this->repository('staging', true)->seite('berlin'));
        self::assertNull($this->repository('staging', true)->seite('muenchen'), 'ohne Datei keine Seite');
    }

    public function testEinleitungUndInhaltWerdenAnDerErstenH2Getrennt(): void
    {
        $seite = $this->repository()->seite('koeln');

        self::assertNotNull($seite);
        self::assertStringContainsString('Einleitung der Testseite', $seite->einleitungHtml);
        self::assertStringNotContainsString('<h2', $seite->einleitungHtml);
        self::assertStringStartsWith('<h2 id="erster-abschnitt">', $seite->inhaltHtml);
        self::assertSame([['frage' => 'Testfrage Köln?', 'antwort' => 'Testantwort Köln.']], $seite->faq);
        self::assertTrue($seite->indexierbar());
        self::assertFalse($this->repository()->seite('dresden')?->indexierbar(), 'freigegeben, aber ohne lokalen Bezug');
    }

    public function testNachbarnSindDieNaechstenSichtbarenSeiten(): void
    {
        $repo = $this->repository('staging', true);
        $koeln = $repo->stadt('koeln');
        self::assertNotNull($koeln);

        $nachbarn = array_map(static fn ($s): string => $s->slug, $repo->nachbarn($koeln));

        // Sichtbar sind in den Fixtures nur Köln, Bonn, Dresden und Berlin (Entwurf, wegen SHOW_DRAFTS)
        self::assertSame(['bonn', 'dresden', 'berlin'], $nachbarn);
    }

    public function testNachbarnMitEchtenInhaltenSindNachEntfernungSortiert(): void
    {
        $repo = $this->repository('staging', true, dirname(__DIR__, 3) . '/content/staedte');
        $koeln = $repo->stadt('koeln');
        self::assertNotNull($koeln);
        $nachbarn = $repo->nachbarn($koeln);

        self::assertLessThanOrEqual(3, count($nachbarn));
        $entfernungen = array_map(static fn ($s): float => $koeln->entfernungKm($s), $nachbarn);
        $sortiert = $entfernungen;
        sort($sortiert);
        self::assertSame($sortiert, $entfernungen);
    }

    public function testGruppierungNachBundesland(): void
    {
        $gruppen = $this->repository()->nachBundesland();

        self::assertCount(16, $gruppen);
        self::assertSame('Baden-Württemberg', array_key_first($gruppen));
        self::assertSame(42, array_sum(array_map('count', $gruppen)));
    }

    public function testRegionAusNameOderSlug(): void
    {
        $repo = $this->repository();

        self::assertSame('frankfurt-am-main', $repo->nachNameOderSlug('Frankfurt am Main')?->slug);
        self::assertSame('Köln', $repo->nachNameOderSlug('koeln')?->name);
        self::assertNull($repo->nachNameOderSlug('Atlantis'));
    }

    public function testSitemapEnthaeltNurFreigegebeneIndexierbareSeiten(): void
    {
        $urls = iterator_to_array((new StadtSitemapProvider($this->repository('staging', true)))->sitemapUrls(), false);

        self::assertSame([['loc' => '/hausverwaltung-koeln/', 'lastmod' => '2026-09-24']], $urls);
    }

    public function testEchteStadttexteSindGueltig(): void
    {
        $repo = $this->repository('staging', true, dirname(__DIR__, 3) . '/content/staedte');

        self::assertSame([], $repo->fehlermeldungen(), 'content/staedte/*.md: Frontmatter prüfen');
        foreach ($repo->sichtbareSeiten() as $seite) {
            self::assertLessThanOrEqual(160, mb_strlen($seite->beschreibung), $seite->stadt->slug . ': Beschreibung zu lang');
        }
    }
}
