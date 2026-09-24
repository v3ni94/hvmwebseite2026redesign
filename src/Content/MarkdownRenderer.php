<?php

declare(strict_types=1);

namespace Hvm\Content;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\MarkdownConverter;

/**
 * Rendert Wissensartikel-Markdown sicher zu HTML (docs/architektur.md Abschnitt 1 und 4):
 * `html_input: escape` entschärft eingebettetes HTML, `allow_unsafe_links: false` blockiert
 * z. B. `javascript:`-Links. Liest zugleich das YAML-Frontmatter (league/commonmark
 * FrontMatterExtension, symfony/yaml) und vergibt stabile id-Attribute an H2-Überschriften
 * für das Inhaltsverzeichnis.
 */
final class MarkdownRenderer
{
    private readonly MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new FrontMatterExtension());
        $this->converter = new MarkdownConverter($environment);
    }

    /**
     * @return array{frontmatter: array<string, mixed>, html: string, ueberschriften: list<array{id: string, text: string}>}
     */
    public function render(string $markdownMitFrontmatter): array
    {
        $result = $this->converter->convert($markdownMitFrontmatter);
        $frontmatter = $result instanceof RenderedContentWithFrontMatter ? $result->getFrontMatter() : [];
        $genutzteIds = [];
        $ueberschriften = [];

        $html = (string) preg_replace_callback(
            '#<h2>(.*?)</h2>#s',
            static function (array $m) use (&$ueberschriften, &$genutzteIds): string {
                $text = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $id = self::slug($text);
                $basis = $id;
                $zaehler = 2;
                while (isset($genutzteIds[$id])) {
                    $id = $basis . '-' . $zaehler++;
                }
                $genutzteIds[$id] = true;
                $ueberschriften[] = ['id' => $id, 'text' => $text];

                return '<h2 id="' . $id . '">' . $m[1] . '</h2>';
            },
            (string) $result
        );

        return [
            'frontmatter' => is_array($frontmatter) ? $frontmatter : [],
            'html' => $html,
            'ueberschriften' => $ueberschriften,
        ];
    }

    /**
     * Einfache, deterministische Kennung: Kleinschreibung, Umlaute und ß in ae/oe/ue/ss,
     * alles andere zu Bindestrichen. Gleiche Regel wie in resources/js/search.js (Normalisierung).
     */
    public static function slug(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');

        return $text !== '' ? $text : 'abschnitt';
    }
}
