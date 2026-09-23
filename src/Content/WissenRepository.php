<?php

declare(strict_types=1);

namespace Hvm\Content;

use Hvm\Support\Config;
use Hvm\Support\Log;

/**
 * Liest content/wissen/*.md (Markdown mit Frontmatter), validiert die Pflichtfelder aus
 * docs/wissen-inhaltsplan.md Abschnitt 2 und rendert den Inhalt sicher zu HTML
 * (Hvm\Content\MarkdownRenderer). Ergebnis wird einmal pro Anfrage aufgebaut und dann
 * gehalten (der Dienst ist im Container eine geteilte Instanz je Anfrage).
 *
 * Veröffentlicht werden nur Artikel mit `freigabe: ja`. Bei SHOW_DRAFTS=true erscheinen
 * Entwürfe zusätzlich, aber nie in Produktion (APP_ENV=production erzwingt das unabhängig
 * vom Wert der Umgebungsvariable, siehe docs/architektur.md Abschnitt 5).
 */
final class WissenRepository
{
    private const MAX_FAQ = 5;

    private const MAX_KURZFASSUNG = 5;

    private readonly string $verzeichnis;

    private readonly MarkdownRenderer $renderer;

    /** @var list<Article>|null */
    private ?array $alle = null;

    /** @var array<string, list<string>> Dateiname (ohne Pfad) => Validierungsfehler */
    private array $fehler = [];

    public function __construct(
        private readonly Config $config,
        private readonly Log $log,
        ?string $verzeichnis = null,
    ) {
        $this->verzeichnis = rtrim($verzeichnis ?? ((string) $config->get('app.base_path') . '/content/wissen'), '/');
        $this->renderer = new MarkdownRenderer();
    }

    public function zeigeEntwuerfe(): bool
    {
        // Produktion erzwingt false, unabhängig vom Wert von SHOW_DRAFTS (docs/architektur.md Abschnitt 5).
        if ($this->config->get('app.env') === 'production') {
            return false;
        }

        return (bool) $this->config->get('app.show_drafts', false);
    }

    /**
     * Alle gültigen Artikel, die je nach Umgebung angezeigt werden dürfen
     * (freigegeben, oder Entwurf sofern zeigeEntwuerfe() zutrifft), neueste zuerst.
     *
     * @return list<Article>
     */
    public function veroeffentlichte(): array
    {
        $zeigeEntwuerfe = $this->zeigeEntwuerfe();
        $artikel = array_values(array_filter(
            $this->alle(),
            static fn (Article $a): bool => $a->freigegeben || $zeigeEntwuerfe
        ));
        usort($artikel, static fn (Article $a, Article $b): int => $b->stand <=> $a->stand);

        return $artikel;
    }

    /**
     * @return list<Article>
     */
    public function nachZielgruppe(string $zielgruppe): array
    {
        return array_values(array_filter(
            $this->veroeffentlichte(),
            static fn (Article $a): bool => $a->zielgruppe === $zielgruppe
        ));
    }

    /**
     * Liefert den Artikel nur, wenn er unter den aktuellen Umständen angezeigt werden darf
     * (freigegeben, oder Entwurf sofern zeigeEntwuerfe() zutrifft). Sonst null (Controller: 404).
     */
    public function findBySlug(string $slug): ?Article
    {
        foreach ($this->veroeffentlichte() as $artikel) {
            if ($artikel->slug === $slug) {
                return $artikel;
            }
        }

        return null;
    }

    /**
     * Verwandte Artikel derselben Zielgruppe, ohne den übergebenen Artikel selbst.
     *
     * @return list<Article>
     */
    public function verwandte(Article $artikel, int $anzahl = 3): array
    {
        $verwandte = array_values(array_filter(
            $this->nachZielgruppe($artikel->zielgruppe),
            static fn (Article $a): bool => $a->slug !== $artikel->slug
        ));

        return array_slice($verwandte, 0, $anzahl);
    }

    /**
     * Diagnose für bin/build-search-index.php --report und Tests: Dateiname => Validierungsfehler.
     *
     * @return array<string, list<string>>
     */
    public function fehlermeldungen(): array
    {
        $this->alle();

        return $this->fehler;
    }

    /**
     * @return list<Article>
     */
    private function alle(): array
    {
        if ($this->alle !== null) {
            return $this->alle;
        }

        $artikel = [];
        $this->fehler = [];
        foreach (glob($this->verzeichnis . '/*.md') ?: [] as $datei) {
            $basisname = basename($datei, '.md');
            try {
                $eintrag = $this->laden($datei, $basisname);
                if ($eintrag !== null) {
                    $artikel[] = $eintrag;
                }
            } catch (\Throwable $e) {
                $this->fehler[$basisname . '.md'] = [$e->getMessage()];
                $this->log->warning('Wissensartikel konnte nicht gelesen werden.', ['datei' => $basisname . '.md']);
            }
        }

        return $this->alle = $artikel;
    }

    private function laden(string $datei, string $basisname): ?Article
    {
        $inhalt = (string) file_get_contents($datei);
        $ergebnis = $this->renderer->render($inhalt);
        $frontmatter = $ergebnis['frontmatter'];

        $fehler = $this->validieren($frontmatter, $basisname);
        if ($fehler !== []) {
            $this->fehler[$basisname . '.md'] = $fehler;
            $this->log->warning('Wissensartikel mit ungültigem Frontmatter übersprungen.', ['datei' => $basisname . '.md', 'anzahl_fehler' => count($fehler)]);

            return null;
        }

        $freigabe = strtolower(trim((string) $frontmatter['freigabe'])) === 'ja';
        $stand = self::datumsWert($frontmatter['stand']);
        $faq = [];
        foreach ((array) ($frontmatter['faq'] ?? []) as $eintrag) {
            if (is_array($eintrag) && isset($eintrag['frage'], $eintrag['antwort'])) {
                $faq[] = ['frage' => (string) $eintrag['frage'], 'antwort' => (string) $eintrag['antwort']];
            }
        }

        return new Article(
            slug: $basisname,
            titel: (string) $frontmatter['titel'],
            zielgruppe: (string) $frontmatter['zielgruppe'],
            beschreibung: (string) $frontmatter['beschreibung'],
            stand: $stand,
            autor: (string) $frontmatter['autor'],
            freigegeben: $freigabe,
            leistung: (string) $frontmatter['leistung'],
            cta: (string) $frontmatter['cta'],
            faq: array_slice($faq, 0, self::MAX_FAQ),
            html: $ergebnis['html'],
            ueberschriften: $ergebnis['ueberschriften'],
            istEntwurf: !$freigabe,
            kurzfassung: self::kurzfassung($frontmatter['kurzfassung'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $frontmatter
     * @return list<string>
     */
    private function validieren(array $frontmatter, string $basisname): array
    {
        $fehler = [];

        foreach (['titel', 'slug', 'zielgruppe', 'beschreibung', 'stand', 'autor', 'freigabe', 'leistung', 'cta'] as $feld) {
            if (!array_key_exists($feld, $frontmatter) || $frontmatter[$feld] === null || $frontmatter[$feld] === '') {
                $fehler[] = sprintf('Pflichtfeld "%s" fehlt.', $feld);
            }
        }
        if ($fehler !== []) {
            return $fehler;
        }

        if ((string) $frontmatter['slug'] !== $basisname) {
            $fehler[] = sprintf('Feld "slug" (%s) entspricht nicht dem Dateinamen (%s).', (string) $frontmatter['slug'], $basisname);
        }
        if (!in_array((string) $frontmatter['zielgruppe'], Article::zielgruppen(), true)) {
            $fehler[] = sprintf('Feld "zielgruppe" (%s) ist ungültig.', (string) $frontmatter['zielgruppe']);
        }
        if (self::datumsWert($frontmatter['stand']) === null) {
            $fehler[] = 'Feld "stand" ist kein gültiges Datum (JJJJ-MM-TT).';
        }
        if (!in_array(strtolower(trim((string) $frontmatter['freigabe'])), ['ja', 'nein'], true)) {
            $fehler[] = 'Feld "freigabe" muss "ja" oder "nein" sein.';
        }
        if (!array_key_exists((string) $frontmatter['cta'], Article::ctaLabels())) {
            $fehler[] = sprintf('Feld "cta" (%s) ist ungültig.', (string) $frontmatter['cta']);
        }
        if (!str_starts_with((string) $frontmatter['leistung'], '/')) {
            $fehler[] = 'Feld "leistung" muss ein interner Pfad sein.';
        }
        $kurz = $frontmatter['kurzfassung'] ?? null;
        if ($kurz !== null && !is_string($kurz) && !(is_array($kurz) && array_is_list($kurz))) {
            $fehler[] = 'Feld "kurzfassung" muss ein Text oder eine Liste sein.';
        } elseif (is_array($kurz) && count($kurz) > self::MAX_KURZFASSUNG) {
            $fehler[] = sprintf('Feld "kurzfassung" hat mehr als %d Einträge.', self::MAX_KURZFASSUNG);
        }
        $faq = $frontmatter['faq'] ?? [];
        if (!is_array($faq)) {
            $fehler[] = 'Feld "faq" muss eine Liste sein.';
        } elseif (count($faq) > self::MAX_FAQ) {
            $fehler[] = sprintf('Feld "faq" hat mehr als %d Einträge.', self::MAX_FAQ);
        } else {
            foreach ($faq as $i => $eintrag) {
                if (!is_array($eintrag) || !isset($eintrag['frage'], $eintrag['antwort']) || $eintrag['frage'] === '' || $eintrag['antwort'] === '') {
                    $fehler[] = sprintf('FAQ-Eintrag %d benötigt "frage" und "antwort".', $i + 1);
                }
            }
        }

        return $fehler;
    }

    /**
     * Optionales Frontmatter-Feld "kurzfassung": Liste kurzer Kernaussagen oder ein einzelner Satz.
     *
     * @return list<string>
     */
    private static function kurzfassung(mixed $wert): array
    {
        $eintraege = is_string($wert) ? [$wert] : (is_array($wert) ? $wert : []);

        return array_slice(array_values(array_filter(
            array_map(static fn ($e): string => is_scalar($e) ? trim((string) $e) : '', $eintraege),
            static fn (string $e): bool => $e !== ''
        )), 0, self::MAX_KURZFASSUNG);
    }

    /**
     * "stand" kommt je nach Schreibweise als Unix-Zeitstempel (unquotiertes YAML-Datum,
     * Standardverhalten von symfony/yaml ohne PARSE_DATETIME) oder als Zeichenkette JJJJ-MM-TT an.
     * Rückgabe immer als JJJJ-MM-TT (UTC, ohne Uhrzeitanteil), oder null bei ungültigem Wert.
     */
    private static function datumsWert(mixed $wert): ?string
    {
        if (is_int($wert)) {
            return gmdate('Y-m-d', $wert);
        }
        if ($wert instanceof \DateTimeInterface) {
            return $wert->format('Y-m-d');
        }
        if (is_string($wert) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $wert) === 1) {
            return $wert;
        }

        return null;
    }
}
