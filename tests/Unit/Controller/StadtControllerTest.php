<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Controller;

use Hvm\Content\StadtRepository;
use Hvm\Http\Kernel;
use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Support\Config;
use Hvm\Support\Log;
use Hvm\Tests\Unit\TestCase;

/**
 * Stadtseiten und Übersicht Betreuungsgebiete mit festen Testinhalten (tests/fixtures/staedte):
 * koeln und bonn (freigegeben, indexierbar), dresden (freigegeben, indexierbar ausgeschaltet wie nach einem
 * Zurücksetzen des Schalters in config/staedte.php), berlin (Entwurf).
 */
final class StadtControllerTest extends TestCase
{
    /**
     * @param array<string, string|null> $env
     */
    private function app(string $appEnv = 'production', array $env = [], ?string $verzeichnis = null, array $nichtIndexierbar = ['dresden']): Kernel
    {
        $kernel = $this->kernel($appEnv, $env);
        $config = $kernel->container()->get(Config::class);
        $config->set('staedte', array_map(
            static fn (array $s): array => in_array($s['slug'], $nichtIndexierbar, true) ? ['indexierbar' => false] + $s : $s,
            $config->array('staedte')
        ));
        $kernel->container()->instance(StadtRepository::class, new StadtRepository(
            $config,
            $kernel->container()->get(Log::class),
            $verzeichnis ?? self::basePath() . '/tests/fixtures/staedte'
        ));

        return $kernel;
    }

    private function aufruf(string $pfad, string $appEnv = 'production', array $env = []): Response
    {
        return $this->app($appEnv, $env)->handle(Request::create('GET', $pfad));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function jsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $m);

        return array_map(static fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $m[1]);
    }

    /**
     * @param list<array<string, mixed>> $schemas
     * @return list<array<string, mixed>>
     */
    private static function typ(array $schemas, string $typ): array
    {
        return array_values(array_filter($schemas, static fn (array $s): bool => in_array($typ, (array) ($s['@type'] ?? []), true)));
    }

    public function testFreigegebeneIndexierbareStadtseite(): void
    {
        $response = $this->aufruf('/hausverwaltung-koeln/');
        $html = $response->body();

        self::assertSame(200, $response->status());
        self::assertSame(1, preg_match_all('#<h1[\s>]#', $html));
        self::assertMatchesRegularExpression('#<h1>[^<]+</h1>#', $html);
        self::assertStringNotContainsString('style="', $html);
        self::assertStringContainsString('<title>Hausverwaltung Köln: Testseite für WEG und Miete</title>', $html);
        self::assertStringContainsString('<meta name="description" content="Testbeschreibung der Stadtseite Köln für die automatisierten Tests.">', $html);
        self::assertStringContainsString('<link rel="canonical" href="' . self::BASE_URL . '/hausverwaltung-koeln/">', $html);
        self::assertStringContainsString('<meta name="robots" content="index, follow', $html);

        // Einleitung, PLZ-Block, Bestand, Inhalt, FAQ, CTA, Kontakt
        self::assertStringContainsString('Einleitung der Testseite für Köln', $html);
        self::assertStringContainsString('Betreute Postleitzahlen', $html);
        self::assertStringContainsString('50000 bis 51999', $html);
        self::assertStringContainsString('Bereits von uns verwaltet in der Region:', $html);
        self::assertStringContainsString('<li>Köln</li>', $html);
        self::assertStringContainsString('Inhalt des ersten Abschnitts.', $html);
        self::assertStringContainsString('Testfrage Köln?', $html);
        self::assertStringContainsString('region=K%C3%B6ln', $html);
        self::assertStringContainsString('02431 9550300', $html);
        self::assertStringContainsString('Montag bis Freitag, 8 bis 16 Uhr', $html);
        self::assertStringContainsString('Betreuung durch Mitarbeiter der Hausverwaltung Müller GmbH vor Ort in der Region. Büro und Verwaltungssitz: Rheinpromenade 13, 40789 Monheim am Rhein. Termine nach Absprache.', $html);
        self::assertStringNotContainsString('Büro am Hauptsitz', $html);
        foreach (['/weg-verwaltung/', '/mietverwaltung/', '/se-verwaltung/', '/wertgutachten/'] as $leistung) {
            self::assertStringContainsString('href="' . $leistung . '"', $html);
        }
        self::assertStringContainsString('c-vertrauensleiste', $html);
    }

    public function testNachbarnVerlinkenNurErreichbareStadtseiten(): void
    {
        $html = $this->aufruf('/hausverwaltung-koeln/')->body();

        self::assertStringContainsString('href="/hausverwaltung-bonn/"', $html);
        self::assertStringContainsString('href="/hausverwaltung-dresden/"', $html);
        self::assertStringNotContainsString('href="/hausverwaltung-berlin/"', $html, 'Entwurf ist in Produktion nicht erreichbar');
        self::assertStringNotContainsString('href="/hausverwaltung-duesseldorf/"', $html, 'keine Datei, kein Link');
    }

    public function testStrukturierteDatenOhneLocalBusinessJeStadt(): void
    {
        $schemas = self::jsonLd($this->aufruf('/hausverwaltung-koeln/')->body());

        $service = self::typ($schemas, 'Service');
        self::assertCount(1, $service);
        self::assertSame(self::BASE_URL . '/hausverwaltung-koeln/#leistung', $service[0]['@id']);
        self::assertSame(['@id' => self::BASE_URL . '/#organisation'], $service[0]['provider']);
        self::assertSame('City', $service[0]['areaServed'][0]['@type']);
        self::assertSame('Köln', $service[0]['areaServed'][0]['name']);
        self::assertSame(['@type' => 'PostalCodeRangeSpecification', 'postalCodeBegin' => '50000', 'postalCodeEnd' => '51999'], $service[0]['areaServed'][1]['postalCodeRange']);

        $krumen = self::typ($schemas, 'BreadcrumbList');
        self::assertCount(1, $krumen);
        self::assertSame(['Start', 'Betreuungsgebiete', 'Köln'], array_column($krumen[0]['itemListElement'], 'name'));

        // Einziger LocalBusiness (RealEstateAgent) ist die Organisation mit dem Hauptsitz
        $lokal = self::typ($schemas, 'RealEstateAgent');
        self::assertCount(1, $lokal);
        self::assertSame(self::BASE_URL . '/#organisation', $lokal[0]['@id']);
        self::assertSame('Monheim am Rhein', $lokal[0]['address']['addressLocality']);
        self::assertSame(1, substr_count(json_encode($schemas, JSON_UNESCAPED_UNICODE), '"PostalAddress"'), 'keine Anschrift je Stadt');
        self::assertCount(1, self::typ($schemas, 'FAQPage'));
    }

    public function testHauptsitzseiteNenntDasBueroAmHauptsitz(): void
    {
        $html = $this->app('production', [], self::basePath() . '/content/staedte')->handle(Request::create('GET', '/hausverwaltung-monheim-am-rhein/'))->body();

        self::assertStringContainsString('Büro am Hauptsitz der Hausverwaltung Müller GmbH: Rheinpromenade 13, 40789 Monheim am Rhein. Termine nach Absprache.', $html);
        self::assertStringNotContainsString('Betreuung durch Mitarbeiter der', $html);
    }

    public function testOhneLokalenBezugIndexierbarMitStandardkonfiguration(): void
    {
        $response = $this->app('production', [], null, [])->handle(Request::create('GET', '/hausverwaltung-dresden/'));
        self::assertSame(200, $response->status());
        self::assertStringContainsString('<meta name="robots" content="index, follow', $response->body());

        $sitemap = $this->app('production', [], null, [])->handle(Request::create('GET', '/sitemap.xml'))->body();
        foreach (['koeln', 'bonn', 'dresden'] as $slug) {
            self::assertStringContainsString('<loc>' . self::BASE_URL . '/hausverwaltung-' . $slug . '/</loc>', $sitemap);
        }
        self::assertStringNotContainsString('/hausverwaltung-berlin/', $sitemap, 'Entwurf nie in der Sitemap');
    }

    public function testStadtOhneLokalenBezugIstNoindexMitCanonicalAufSichSelbst(): void
    {
        $response = $this->aufruf('/hausverwaltung-dresden/');
        $html = $response->body();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);
        self::assertStringContainsString('<link rel="canonical" href="' . self::BASE_URL . '/hausverwaltung-dresden/">', $html);
        self::assertStringNotContainsString('Bereits von uns verwaltet', $html, 'ohne bestand_orte kein Bestandsblock');
    }

    public function testEntwurfNurMitShowDraftsAusserhalbDerProduktion(): void
    {
        self::assertSame(404, $this->aufruf('/hausverwaltung-berlin/')->status());
        self::assertSame(404, $this->aufruf('/hausverwaltung-berlin/', 'production', ['SHOW_DRAFTS' => 'true'])->status());

        $response = $this->aufruf('/hausverwaltung-berlin/', 'staging', ['SHOW_DRAFTS' => 'true']);
        self::assertSame(200, $response->status());
        self::assertStringContainsString('Entwurf, nicht freigegeben', $response->body());
        self::assertStringContainsString('c-entwurf', $response->body());
    }

    public function testFehlendeDateiUndUnbekannteStadtSind404(): void
    {
        self::assertSame(404, $this->aufruf('/hausverwaltung-muenchen/', 'staging', ['SHOW_DRAFTS' => 'true'])->status());
        self::assertSame(404, $this->aufruf('/hausverwaltung-atlantis/', 'staging', ['SHOW_DRAFTS' => 'true'])->status());
        self::assertSame(404, $this->aufruf('/hausverwaltung-hamburg/', 'staging', ['SHOW_DRAFTS' => 'true'])->status(), 'ungültiges Frontmatter');
    }

    public function testUebersichtZeigtAlle42StaedteNachBundesland(): void
    {
        $response = $this->aufruf('/betreuungsgebiete/');
        $html = $response->body();

        self::assertSame(200, $response->status());
        self::assertSame(1, preg_match_all('#<h1[\s>]#', $html));
        self::assertStringNotContainsString('style="', $html);
        self::assertSame(42, substr_count($html, 'class="c-gebiete__eintrag'));
        self::assertSame(16, substr_count($html, 'class="c-gebiete__land"'));
        self::assertSame(42, substr_count($html, '<circle class="c-karte-de__punkt'));
        self::assertSame(1, substr_count($html, 'c-karte-de__punkt--hauptsitz'));
        self::assertStringContainsString('PLZ 50000 bis 51999', $html);
        self::assertStringContainsString('PLZ 00001 bis 01999', $html);
        self::assertStringContainsString('href="/hausverwaltung-koeln/"', $html);
        self::assertStringNotContainsString('href="/hausverwaltung-berlin/"', $html, 'Entwurf in Produktion nicht verlinkt');
        self::assertStringNotContainsString('Partnerbetreuung', $html, 'alte Statusplatzhalter entfernt');
        self::assertStringNotContainsString('noindex', $html);
    }

    public function testSitemapUndLlmsEnthaltenNurIndexierbareStadtseiten(): void
    {
        $sitemap = $this->aufruf('/sitemap.xml')->body();
        self::assertStringContainsString('<loc>' . self::BASE_URL . '/hausverwaltung-koeln/</loc>', $sitemap);
        self::assertStringNotContainsString('/hausverwaltung-dresden/', $sitemap);
        self::assertStringNotContainsString('/hausverwaltung-berlin/', $sitemap);
        self::assertStringContainsString('<loc>' . self::BASE_URL . '/betreuungsgebiete/</loc>', $sitemap);

        $staging = $this->aufruf('/sitemap.xml', 'staging', ['SHOW_DRAFTS' => 'true'])->body();
        self::assertStringNotContainsString('/hausverwaltung-berlin/', $staging, 'Entwürfe nie in der Sitemap');

        $llms = $this->aufruf('/llms.txt')->body();
        self::assertStringContainsString('## Betreuungsgebiete', $llms);
        self::assertStringContainsString(self::BASE_URL . '/hausverwaltung-koeln/', $llms);
        self::assertStringNotContainsString('/hausverwaltung-dresden/', $llms);
        self::assertStringNotContainsString('/hausverwaltung-berlin/', $llms);
    }

    public function testAngebotUebernimmtStadtnamenAlsRegion(): void
    {
        $html = $this->aufruf('/angebot/?region=Frankfurt%20am%20Main', 'development')->body();
        self::assertStringContainsString('<input type="hidden" name="region" value="Frankfurt am Main">', $html);

        $html = $this->aufruf('/angebot/?region=monheim-am-rhein', 'development')->body();
        self::assertStringContainsString('<input type="hidden" name="region" value="Monheim am Rhein">', $html);
    }
}
