<?php

declare(strict_types=1);

namespace Hvm\Controller;

use Hvm\Content\WissenRepository;
use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Http\Router;
use Hvm\Support\Config;
use Hvm\Support\Clock;
use Hvm\Support\Container;
use Hvm\Support\SitemapProvider;

/**
 * sitemap.xml, robots.txt, llms.txt (docs/seo-geo.md) und security.txt (RFC 9116).
 *
 * Die Sitemap enthält alle öffentlichen Seiten aus config/seiten.php ('sitemap' => true), deren Pfad in der
 * aktuellen Umgebung als GET-Route registriert ist, sowie Adressen aus registrierten SitemapProvider-Klassen
 * (config/app.php, 'sitemap_providers').
 */
final class SeoController
{
    /** KI-Crawler, die in Produktion ausdrücklich zugelassen (oder per ai_crawlers=false gesperrt) werden. */
    public const KI_CRAWLER = ['GPTBot', 'OAI-SearchBot', 'PerplexityBot', 'ClaudeBot', 'Google-Extended'];

    /** Reihenfolge der Seiten in llms.txt, gruppiert. Nur Seiten mit 'sitemap' => true werden ausgegeben. */
    private const LLMS_GRUPPEN = [
        'Leistungen' => ['weg-verwaltung', 'mietverwaltung', 'se-verwaltung', 'asset-management', 'verwalterwechsel', 'vermietung', 'verkauf', 'wertgutachten'],
        'Unternehmen und Kontakt' => ['fakten', 'ueber-uns', 'betreuungsgebiete', 'referenzen', 'service', 'notfall', 'kontakt', 'angebot', 'wissen', 'karriere'],
        'Optional' => ['impressum', 'datenschutz', 'barrierefreiheit'],
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Router $router,
        private readonly Container $container,
        private readonly WissenRepository $wissen,
    ) {
    }

    public function sitemap(Request $request, array $params = []): Response
    {
        $base = $this->baseUrl();
        $entries = [];
        $registriert = [];
        foreach ($this->router->staticGetRoutes() as $route) {
            $registriert[$route['path']] = true;
        }

        foreach ($this->config->array('seiten') as $slug => $meta) {
            if (!is_array($meta) || ($meta['sitemap'] ?? false) !== true) {
                continue;
            }
            $pfad = (string) ($meta['pfad'] ?? '');
            if (!isset($registriert[$pfad])) {
                continue;
            }
            $entries[$pfad] = $this->lastmod((string) $slug);
        }

        foreach ((array) $this->config->get('app.sitemap_providers', []) as $class) {
            $provider = $this->container->get((string) $class);
            if (!$provider instanceof SitemapProvider) {
                continue;
            }
            foreach ($provider->sitemapUrls() as $url) {
                $entries[$url['loc']] = $url['lastmod'] ?? null;
            }
        }

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach ($entries as $path => $lastmod) {
            $xml->startElement('url');
            $xml->writeElement('loc', $base . $path);
            if (is_string($lastmod) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastmod)) {
                $xml->writeElement('lastmod', $lastmod);
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();

        return Response::xml($xml->outputMemory())->withHeader('Cache-Control', 'public, max-age=3600');
    }

    /**
     * lastmod nur mit belastbarem Datum: Freigabedatum der Seite (config/freigaben.php), das bei jeder
     * inhaltlichen Freigabe gesetzt wird. Das Dateiänderungsdatum der Vorlage ist nach Checkout oder
     * Container-Build nicht aussagekräftig und wird deshalb nicht verwendet (docs/seo-geo.md).
     * Die Wissensübersicht übernimmt zusätzlich das neueste Stand-Datum der veröffentlichten Artikel.
     */
    private function lastmod(string $slug): ?string
    {
        $datum = $this->config->get('freigaben.' . $slug . '.datum');
        $datum = is_string($datum) && $datum !== '' ? $datum : null;

        if ($slug === 'wissen') {
            foreach ($this->freigegebeneArtikel() as $artikel) {
                if ($datum === null || $artikel->stand > $datum) {
                    $datum = $artikel->stand;
                }
            }
        }

        return $datum;
    }

    public function robots(Request $request, array $params = []): Response
    {
        if ($this->config->get('app.env') !== 'production') {
            return Response::text("User-agent: *\nDisallow: /\n")->withHeader('Cache-Control', 'public, max-age=3600');
        }

        $zulassen = (bool) $this->config->get('app.ai_crawlers', true);
        $zeilen = ['User-agent: *', 'Disallow: /admin/', ''];
        $zeilen[] = $zulassen
            ? '# KI-Crawler und Antwortmaschinen ausdrücklich zugelassen (config/app.php ai_crawlers)'
            : '# KI-Crawler gesperrt (config/app.php ai_crawlers)';
        foreach (self::KI_CRAWLER as $crawler) {
            $zeilen[] = 'User-agent: ' . $crawler;
        }
        if ($zulassen) {
            $zeilen[] = 'Allow: /';
            $zeilen[] = 'Disallow: /admin/';
        } else {
            $zeilen[] = 'Disallow: /';
        }
        $zeilen[] = '';
        $zeilen[] = 'Sitemap: ' . $this->baseUrl() . '/sitemap.xml';

        return Response::text(implode("\n", $zeilen) . "\n")->withHeader('Cache-Control', 'public, max-age=3600');
    }

    /**
     * llms.txt nach der Konvention von llmstxt.org: H1 mit Namen, Kurzbeschreibung als Zitat, kurze Fakten,
     * danach Linklisten je Abschnitt. Wissensartikel nur mit freigabe: ja, in keiner Umgebung Entwürfe.
     */
    public function llms(Request $request, array $params = []): Response
    {
        $firma = $this->config->array('unternehmen');
        $kennzahlen = $this->config->array('kennzahlen');
        $fakten = $this->config->array('fakten');
        $seiten = $this->config->array('seiten');
        $base = $this->baseUrl();
        $anschrift = (array) ($firma['anschrift'] ?? []);
        $ort = (string) ($anschrift['ort'] ?? '');

        $leistungen = [];
        foreach ((array) ($fakten['definitionen'] ?? []) as $definition) {
            $leistungen[] = (string) $definition['begriff'];
        }

        $md = [];
        $md[] = '# ' . ($firma['name'] ?? '');
        $md[] = '';
        $md[] = sprintf(
            '> %s (%s) ist eine Hausverwaltung mit Sitz in %s für %s. Gegründet am %s.',
            $firma['name'] ?? '',
            $firma['kurzname'] ?? '',
            $ort,
            self::aufzaehlung($leistungen),
            self::datum((string) ($firma['gruendung'] ?? ''))
        );
        $md[] = '';
        $md[] = sprintf('- Sitz: %s, %s %s', $anschrift['strasse'] ?? '', $anschrift['plz'] ?? '', $ort);
        $md[] = sprintf('- Register: %s, %s', $firma['registergericht'] ?? '', $firma['hrb'] ?? '');
        $md[] = '- Geschäftsführer: ' . ($firma['geschaeftsfuehrer'] ?? '');
        $md[] = sprintf(
            '- Bestand: %d Verwaltungseinheiten in %d Objekten (Stand %s)',
            (int) ($kennzahlen['einheiten'] ?? 0),
            (int) ($kennzahlen['objekte'] ?? 0),
            self::datum((string) ($kennzahlen['stichtag'] ?? ''))
        );
        $mitglied = array_map(
            static fn (array $m): string => ($m['name'] ?? '') === ($m['kurz'] ?? '') ? (string) $m['kurz'] : $m['kurz'] . ' (' . $m['name'] . ')',
            (array) ($firma['mitgliedschaften'] ?? [])
        );
        if ($mitglied !== []) {
            $md[] = '- Mitgliedschaften: ' . self::aufzaehlung($mitglied);
        }
        $kontakt = 'E-Mail ' . ($firma['email'] ?? '');
        if (!empty($firma['telefon'])) {
            $kontakt = 'Telefon ' . $firma['telefon'] . ', ' . $kontakt;
        }
        $md[] = '- Kontakt: ' . $kontakt;
        $md[] = '';
        $md[] = 'Alle Angaben mit Stand-Datum stehen auf der Seite [Die HVM in Zahlen und Fakten](' . $base . '/fakten/). Die Wissensartikel sind allgemeine Informationen und keine Rechtsberatung.';

        foreach (self::LLMS_GRUPPEN as $titel => $slugs) {
            $zeilen = [];
            foreach ($slugs as $slug) {
                $meta = $seiten[$slug] ?? null;
                if (!is_array($meta) || ($meta['sitemap'] ?? false) !== true) {
                    continue;
                }
                $zeilen[] = sprintf('- [%s](%s%s): %s', $meta['titel'] ?? $slug, $base, $meta['pfad'] ?? '/', $meta['beschreibung'] ?? '');
            }
            if ($zeilen === []) {
                continue;
            }
            if ($titel === 'Optional') {
                $md = [...$md, '', '## Wissen', ...$this->artikelZeilen()];
            }
            $md = [...$md, '', '## ' . $titel, ...$zeilen];
        }

        return new Response(implode("\n", $md) . "\n", 200, [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * @return list<string>
     */
    private function artikelZeilen(): array
    {
        $zeilen = [];
        foreach ($this->freigegebeneArtikel() as $artikel) {
            $zeilen[] = sprintf('- [%s](%s/wissen/%s/): %s (Stand %s)', $artikel->titel, $this->baseUrl(), $artikel->slug, $artikel->beschreibung, self::datum($artikel->stand));
        }
        if ($zeilen === []) {
            $zeilen[] = '- [Wissen und FAQ](' . $this->baseUrl() . '/wissen/): Übersicht der veröffentlichten Beiträge und häufigen Fragen';
        }

        return $zeilen;
    }

    /**
     * Nur freigegebene Artikel, auch wenn SHOW_DRAFTS Entwürfe sichtbar macht.
     *
     * @return list<\Hvm\Content\Article>
     */
    private function freigegebeneArtikel(): array
    {
        return array_values(array_filter($this->wissen->veroeffentlichte(), static fn ($a): bool => $a->freigegeben));
    }

    /**
     * /.well-known/security.txt nach RFC 9116. Expires liegt knapp unter einem Jahr in der Zukunft
     * (Tagesbeginn UTC in einem Jahr), wird also bei jeder Anfrage fortgeschrieben und läuft nie ab.
     */
    public function securityTxt(Request $request, array $params = []): Response
    {
        $expires = Clock::now()->modify('+1 year')->setTime(0, 0);
        $zeilen = [
            'Contact: mailto:' . (string) $this->config->get('unternehmen.email', 'info@muellerhv.de'),
            'Expires: ' . $expires->format('Y-m-d\TH:i:s\Z'),
            'Preferred-Languages: de',
            'Canonical: ' . $this->baseUrl() . '/.well-known/security.txt',
        ];

        return Response::text(implode("\n", $zeilen) . "\n")->withHeader('Cache-Control', 'public, max-age=86400');
    }

    private function baseUrl(): string
    {
        return rtrim((string) $this->config->get('app.url'), '/');
    }

    private static function datum(string $iso): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) ? $m[3] . '.' . $m[2] . '.' . $m[1] : $iso;
    }

    /**
     * @param list<string> $teile
     */
    private static function aufzaehlung(array $teile): string
    {
        if (count($teile) < 2) {
            return implode('', $teile);
        }
        $letztes = array_pop($teile);

        return implode(', ', $teile) . ' und ' . $letztes;
    }
}
