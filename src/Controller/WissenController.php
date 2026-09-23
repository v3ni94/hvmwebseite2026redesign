<?php

declare(strict_types=1);

namespace Hvm\Controller;

use Hvm\Content\Article;
use Hvm\Content\FaqRepository;
use Hvm\Content\WissenRepository;
use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Support\Config;
use Hvm\View\PageMeta;
use Hvm\View\SchemaBuilder;
use Hvm\View\View;

/**
 * Wissens- und FAQ-Bereich (MP Abschnitt 7, docs/wissen-inhaltsplan.md):
 * /wissen/ (Übersicht mit Suche und Filter) und /wissen/{slug}/ (Artikel).
 */
final class WissenController
{
    public function __construct(
        private readonly View $view,
        private readonly PageMeta $meta,
        private readonly SchemaBuilder $schemaBuilder,
        private readonly Config $config,
        private readonly ErrorController $errors,
        private readonly WissenRepository $wissen,
        private readonly FaqRepository $faq,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function index(Request $request, array $params = []): Response
    {
        $filter = $request->queryValue('zielgruppe');
        if ($filter !== null && !in_array($filter, Article::zielgruppen(), true)) {
            $filter = null;
        }
        $suchbegriff = trim((string) $request->queryValue('q', ''));
        $zielgruppen = $filter !== null ? [$filter] : Article::zielgruppen();

        $abschnitte = [];
        foreach ($zielgruppen as $zielgruppe) {
            $artikel = $this->wissen->nachZielgruppe($zielgruppe);
            if ($suchbegriff !== '') {
                $artikel = array_values(array_filter(
                    $artikel,
                    static fn (Article $a): bool => self::treffer($a, $suchbegriff)
                ));
            }
            $faqGruppe = $this->faq->gruppeFuer($zielgruppe);
            if ($artikel === [] && ($faqGruppe === null || $faqGruppe->fragen === []) && $suchbegriff !== '') {
                continue;
            }
            $abschnitte[] = [
                'zielgruppe' => $zielgruppe,
                'titel' => Article::zielgruppeLabel($zielgruppe),
                'artikel' => $artikel,
                'faq' => $faqGruppe,
            ];
        }

        $page = $this->meta->build('wissen', $request->path());
        $faqSchema = $this->faqPageSchema($zielgruppen);
        if ($faqSchema !== null) {
            $page['schema'][] = $faqSchema;
        }

        $zielgruppeLabels = [];
        foreach (Article::zielgruppen() as $zielgruppe) {
            $zielgruppeLabels[$zielgruppe] = Article::zielgruppeLabel($zielgruppe);
        }

        return $this->view->response('pages/wissen.html.twig', [
            'page' => $page,
            'abschnitte' => $abschnitte,
            'filterAktiv' => $filter,
            'suchbegriff' => $suchbegriff,
            'alleZielgruppen' => Article::zielgruppen(),
            'zielgruppeLabels' => $zielgruppeLabels,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function show(Request $request, array $params = []): Response
    {
        $slug = (string) ($params['slug'] ?? '');
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return $this->errors->notFound($request);
        }
        $artikel = $this->wissen->findBySlug($slug);
        if ($artikel === null) {
            return $this->errors->notFound($request);
        }

        $page = $this->meta->build('wissen-artikel', $request->path(), [
            'heading' => $artikel->titel,
            'description' => $artikel->beschreibung,
        ]);
        $page['schema'] = $this->artikelSchema($artikel, $page);

        $ctaLabels = Article::ctaLabels();
        $ctaUrls = Article::ctaUrls();

        return $this->view->response('pages/wissen-artikel.html.twig', [
            'page' => $page,
            'artikel' => $artikel,
            'zielgruppeLabel' => Article::zielgruppeLabel($artikel->zielgruppe),
            'leistungLabel' => $this->leistungLabel($artikel->leistung),
            'verwandte' => $this->wissen->verwandte($artikel),
            'ctaLabel' => $ctaLabels[$artikel->cta] ?? $ctaLabels['kontakt'],
            'ctaUrl' => $ctaUrls[$artikel->cta] ?? $ctaUrls['kontakt'],
        ]);
    }

    /**
     * Sucht die Kurzbezeichnung der Leistungsseite (config/seiten.php, Feld "pfad") zum
     * Frontmatter-Feld "leistung", damit der Link im Artikel einen sprechenden Text trägt
     * statt des rohen Pfads.
     */
    private function leistungLabel(string $pfad): string
    {
        foreach ((array) $this->config->get('seiten', []) as $eintrag) {
            if (is_array($eintrag) && ($eintrag['pfad'] ?? null) === $pfad) {
                return (string) ($eintrag['titel'] ?? $pfad);
            }
        }

        return 'die passende Leistungsseite';
    }

    /**
     * @param array<string, mixed> $page
     * @return list<array<string, mixed>>
     */
    private function artikelSchema(Article $artikel, array $page): array
    {
        $schemas = $page['schema'];
        $schemas[] = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $artikel->titel,
            'description' => $artikel->beschreibung,
            'datePublished' => $artikel->stand,
            'dateModified' => $artikel->stand,
            'inLanguage' => 'de',
            'author' => ['@type' => 'Organization', 'name' => $artikel->autor],
            'publisher' => ['@id' => $this->schemaBuilder->organizationId()],
            'mainEntityOfPage' => $page['canonical'],
        ];
        if ($artikel->faq !== []) {
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => array_map(
                    static fn (array $f): array => [
                        '@type' => 'Question',
                        'name' => $f['frage'],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => self::klartext($f['antwort'])],
                    ],
                    $artikel->faq
                ),
            ];
        }

        return $schemas;
    }

    /**
     * @param list<string> $zielgruppen
     * @return array<string, mixed>|null
     */
    private function faqPageSchema(array $zielgruppen): ?array
    {
        $fragen = [];
        foreach ($zielgruppen as $zielgruppe) {
            $gruppe = $this->faq->gruppeFuer($zielgruppe);
            if ($gruppe === null) {
                continue;
            }
            foreach ($gruppe->veroeffentlichte() as $frage) {
                $fragen[] = [
                    '@type' => 'Question',
                    'name' => $frage->frage,
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => self::klartext($frage->antwort)],
                ];
            }
        }
        if ($fragen === []) {
            return null;
        }

        return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $fragen];
    }

    /**
     * Mehrwortsuche mit UND-Verknüpfung, Normalisierung wie resources/js/search.js
     * (Kleinschreibung, Umlaute und ß gleichwertig zu ae/oe/ue/ss).
     */
    private static function treffer(Article $artikel, string $suchbegriff): bool
    {
        $heuhaufen = self::normalisieren($artikel->titel . ' ' . $artikel->beschreibung);
        foreach (array_filter(explode(' ', self::normalisieren($suchbegriff))) as $wort) {
            if (!str_contains($heuhaufen, $wort)) {
                return false;
            }
        }

        return true;
    }

    private static function normalisieren(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');

        return strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    }

    /** Markdown-Links und Hervorhebungen für strukturierte Daten in Klartext umwandeln. */
    private static function klartext(string $markdown): string
    {
        $text = (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $markdown);

        return str_replace(['**', '__'], '', $text);
    }
}
