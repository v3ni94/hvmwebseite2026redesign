<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Seo;

use Hvm\Content\WissenRepository;
use Hvm\Support\Config;
use Hvm\Support\Log;
use Hvm\Tests\Unit\TestCase;
use Hvm\View\PageMeta;

/**
 * SEO-Grundregeln (docs/seo-geo.md Abschnitt 2): Titel höchstens 60, Beschreibungen höchstens 160 Zeichen,
 * eindeutig je Seite und Artikel; Kurzfassung im Wissensartikel optional.
 */
final class SeoMetaTest extends TestCase
{
    private const FIRMA = 'Hausverwaltung Müller GmbH';

    public function testSeitentitelUndBeschreibungenEindeutigUndKurz(): void
    {
        $titel = [];
        $beschreibungen = [];
        foreach ($this->alleSeiten() as $pfad => [$t, $d]) {
            self::assertLessThanOrEqual(PageMeta::MAX_TITEL, mb_strlen($t), $pfad . ': ' . $t);
            self::assertLessThanOrEqual(160, mb_strlen($d), $pfad . ': Beschreibung zu lang');
            self::assertGreaterThanOrEqual(50, mb_strlen($d), $pfad . ': Beschreibung zu kurz');
            self::assertArrayNotHasKey($t, $titel, 'doppelter Titel ' . $pfad . ' und ' . ($titel[$t] ?? ''));
            self::assertArrayNotHasKey($d, $beschreibungen, 'doppelte Beschreibung ' . $pfad);
            $titel[$t] = $pfad;
            $beschreibungen[$d] = $pfad;
        }
    }

    public function testSeitentitelKuerztStufenweise(): void
    {
        self::assertSame('Hausgeld | Hausverwaltung Müller GmbH', PageMeta::seitentitel('Hausgeld', self::FIRMA));
        self::assertSame('Asset Management für Wohnimmobilien | Hausverwaltung Müller', PageMeta::seitentitel('Asset Management für Wohnimmobilien', self::FIRMA));
        self::assertSame('Die Eigentümerversammlung | Hausverwaltung Müller GmbH', PageMeta::seitentitel('Die Eigentümerversammlung: Einberufung, Ablauf, Niederschrift', self::FIRMA));
    }

    public function testKurzfassungWirdAusFrontmatterGelesen(): void
    {
        $artikel = $this->repository()->findBySlug('hausgeld');
        self::assertNotNull($artikel);
        self::assertNotEmpty($artikel->kurzfassung);
        self::assertLessThanOrEqual(5, count($artikel->kurzfassung));
        self::assertFalse($artikel->freigegeben, 'Kurzfassung ändert nichts an der Freigabe');

        $ohne = $this->repository()->findBySlug('hausordnung');
        self::assertNotNull($ohne);
        self::assertSame([], $ohne->kurzfassung);
    }

    public function testArtikelZeigtKurzfassungStandUndAutor(): void
    {
        $response = $this->kernel('staging', ['SHOW_DRAFTS' => 'true'])->handle(\Hvm\Http\Request::create('GET', '/wissen/hausgeld/'));
        $html = $response->body();
        self::assertSame(200, $response->status());
        self::assertStringContainsString('Das Wichtigste in Kürze', $html);
        self::assertStringContainsString('<time datetime="2026-09-23">23.09.2026</time>', $html);
        self::assertStringContainsString('Autor: Hausverwaltung Müller GmbH', $html);
        self::assertStringContainsString('"@type":"Article"', $html);
        self::assertStringContainsString('"dateModified":"2026-09-23"', $html);
        self::assertStringNotContainsString('"@type":"FAQPage"', $html, 'FAQPage nur für freigegebene Artikel');
        self::assertStringContainsString('<meta property="og:type" content="article">', $html);

        $ohne = $this->kernel('staging', ['SHOW_DRAFTS' => 'true'])->handle(\Hvm\Http\Request::create('GET', '/wissen/hausordnung/'));
        self::assertStringNotContainsString('Das Wichtigste in Kürze', $ohne->body());
    }

    /**
     * @return array<string, array{string, string}> Pfad => [Titel, Beschreibung]
     */
    private function alleSeiten(): array
    {
        $seiten = [];
        foreach (require self::basePath() . '/config/seiten.php' as $slug => $meta) {
            if (($meta['sitemap'] ?? false) !== true) {
                continue;
            }
            $seiten[$meta['pfad']] = [(string) ($meta['seitentitel'] ?? PageMeta::seitentitel($meta['titel'], self::FIRMA)), (string) $meta['beschreibung']];
        }
        foreach ($this->repository()->veroeffentlichte() as $artikel) {
            $seiten['/wissen/' . $artikel->slug . '/'] = [PageMeta::seitentitel($artikel->titel, self::FIRMA), $artikel->beschreibung];
        }

        return $seiten;
    }

    private function repository(): WissenRepository
    {
        $config = new Config(['app' => ['env' => 'staging', 'show_drafts' => true, 'base_path' => self::basePath()]]);

        return new WissenRepository($config, new Log(sys_get_temp_dir(), 'hvm-test.log'));
    }
}
