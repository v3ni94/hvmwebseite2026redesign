<?php

declare(strict_types=1);

namespace Hvm\Content;

use Hvm\Support\Config;
use Hvm\Support\Log;

/**
 * Betreuungsgebiete (config/staedte.php) und Stadtseiten (content/staedte/{slug}.md).
 *
 * Frontmatter-Pflichtfelder: titel, beschreibung, stadt, slug, bundesland, stand, freigabe; optional faq
 * (Liste mit frage und antwort). Body Markdown mit H2/H3; der Text vor der ersten H2 ist die Einleitung.
 *
 * Freigabelogik wie Wissensartikel: sichtbar sind Seiten mit freigabe: ja; bei SHOW_DRAFTS=true zusätzlich
 * Entwürfe, in Produktion nie (APP_ENV=production erzwingt das unabhängig von SHOW_DRAFTS).
 */
final class StadtRepository
{
    private readonly string $verzeichnis;

    private readonly MarkdownRenderer $renderer;

    /** @var list<Stadt>|null */
    private ?array $staedte = null;

    /** @var array<string, StadtSeite>|null slug => gültige Seite (unabhängig von der Freigabe) */
    private ?array $seiten = null;

    /** @var array<string, list<string>> Dateiname => Validierungsfehler */
    private array $fehler = [];

    public function __construct(
        private readonly Config $config,
        private readonly Log $log,
        ?string $verzeichnis = null,
    ) {
        $this->verzeichnis = rtrim($verzeichnis ?? ((string) $config->get('app.base_path') . '/content/staedte'), '/');
        $this->renderer = new MarkdownRenderer();
    }

    public function zeigeEntwuerfe(): bool
    {
        if ($this->config->get('app.env') === 'production') {
            return false;
        }

        return (bool) $this->config->get('app.show_drafts', false);
    }

    /**
     * Alle Betreuungsgebiete in der Reihenfolge von config/staedte.php (aufsteigende PLZ).
     *
     * @return list<Stadt>
     */
    public function staedte(): array
    {
        if ($this->staedte === null) {
            $this->staedte = [];
            foreach ($this->config->array('staedte') as $eintrag) {
                if (is_array($eintrag) && isset($eintrag['slug'], $eintrag['name'])) {
                    $this->staedte[] = Stadt::ausKonfiguration($eintrag);
                }
            }
        }

        return $this->staedte;
    }

    public function stadt(string $slug): ?Stadt
    {
        foreach ($this->staedte() as $stadt) {
            if ($stadt->slug === $slug) {
                return $stadt;
            }
        }

        return null;
    }

    public function hauptsitz(): ?Stadt
    {
        foreach ($this->staedte() as $stadt) {
            if ($stadt->hauptsitz) {
                return $stadt;
            }
        }

        return null;
    }

    /**
     * Stadt zu einem Namen oder Slug (Vorbelegung region im Angebots- und Kontaktformular).
     */
    public function nachNameOderSlug(string $wert): ?Stadt
    {
        $wert = trim($wert);
        foreach ($this->staedte() as $stadt) {
            if ($stadt->slug === $wert || mb_strtolower($stadt->name) === mb_strtolower($wert)) {
                return $stadt;
            }
        }

        return null;
    }

    /**
     * Seite nur, wenn sie in der aktuellen Umgebung angezeigt werden darf, sonst null (Controller: 404).
     */
    public function seite(string $slug): ?StadtSeite
    {
        $seite = $this->alleSeiten()[$slug] ?? null;
        if ($seite === null || (!$seite->freigegeben && !$this->zeigeEntwuerfe())) {
            return null;
        }

        return $seite;
    }

    public function hatSichtbareSeite(string $slug): bool
    {
        return $this->seite($slug) !== null;
    }

    /**
     * Sichtbare Seiten in der Reihenfolge von config/staedte.php.
     *
     * @return list<StadtSeite>
     */
    public function sichtbareSeiten(): array
    {
        $ergebnis = [];
        foreach ($this->staedte() as $stadt) {
            $seite = $this->seite($stadt->slug);
            if ($seite !== null) {
                $ergebnis[] = $seite;
            }
        }

        return $ergebnis;
    }

    /**
     * Freigegebene Seiten, unabhängig von SHOW_DRAFTS.
     *
     * @return list<StadtSeite>
     */
    public function freigegebeneSeiten(): array
    {
        return array_values(array_filter($this->sichtbareSeiten(), static fn (StadtSeite $s): bool => $s->freigegeben));
    }

    /**
     * Freigegebene indexierbare Seiten (config/staedte.php indexierbar): sitemap.xml und llms.txt.
     *
     * @return list<StadtSeite>
     */
    public function indexierbareSeiten(): array
    {
        return array_values(array_filter($this->sichtbareSeiten(), static fn (StadtSeite $s): bool => $s->indexierbar()));
    }

    /**
     * Die nächstgelegenen Städte nach Luftlinie, die eine sichtbare Seite haben (keine Links ins Leere).
     *
     * @return list<Stadt>
     */
    public function nachbarn(Stadt $stadt, int $anzahl = 3): array
    {
        $kandidaten = array_values(array_filter(
            $this->staedte(),
            fn (Stadt $s): bool => $s->slug !== $stadt->slug && $this->hatSichtbareSeite($s->slug)
        ));
        usort($kandidaten, static fn (Stadt $a, Stadt $b): int => $stadt->entfernungKm($a) <=> $stadt->entfernungKm($b));

        return array_slice($kandidaten, 0, $anzahl);
    }

    /**
     * Städte gruppiert nach Bundesland (alphabetisch), innerhalb eines Landes nach PLZ.
     *
     * @return array<string, list<Stadt>>
     */
    public function nachBundesland(): array
    {
        $gruppen = [];
        foreach ($this->staedte() as $stadt) {
            $gruppen[$stadt->bundesland][] = $stadt;
        }
        uksort($gruppen, static fn (string $a, string $b): int => strcmp(self::sortierschluessel($a), self::sortierschluessel($b)));

        return $gruppen;
    }

    /**
     * Diagnose für Tests und Prüfwerkzeuge: Dateiname => Validierungsfehler.
     *
     * @return array<string, list<string>>
     */
    public function fehlermeldungen(): array
    {
        $this->alleSeiten();

        return $this->fehler;
    }

    /**
     * @return array<string, StadtSeite>
     */
    private function alleSeiten(): array
    {
        if ($this->seiten !== null) {
            return $this->seiten;
        }
        $this->seiten = [];
        $this->fehler = [];
        foreach (glob($this->verzeichnis . '/*.md') ?: [] as $datei) {
            $basisname = basename($datei, '.md');
            try {
                $seite = $this->laden($datei, $basisname);
                if ($seite !== null) {
                    $this->seiten[$basisname] = $seite;
                }
            } catch (\Throwable $e) {
                $this->fehler[$basisname . '.md'] = [$e->getMessage()];
                $this->log->warning('Stadtseite konnte nicht gelesen werden.', ['datei' => $basisname . '.md']);
            }
        }

        return $this->seiten;
    }

    private function laden(string $datei, string $basisname): ?StadtSeite
    {
        $ergebnis = $this->renderer->render((string) file_get_contents($datei));
        $fm = $ergebnis['frontmatter'];
        $stadt = $this->stadt($basisname);

        $fehler = $this->validieren($fm, $basisname, $stadt);
        if ($fehler !== [] || $stadt === null) {
            $this->fehler[$basisname . '.md'] = $fehler;
            $this->log->warning('Stadtseite mit ungültigem Frontmatter übersprungen.', ['datei' => $basisname . '.md', 'anzahl_fehler' => count($fehler)]);

            return null;
        }

        $html = $ergebnis['html'];
        $position = strpos($html, '<h2');
        $einleitung = $position === false ? $html : substr($html, 0, $position);
        $inhalt = $position === false ? '' : substr($html, $position);

        $faq = [];
        foreach ((array) ($fm['faq'] ?? []) as $eintrag) {
            if (is_array($eintrag) && isset($eintrag['frage'], $eintrag['antwort'])) {
                $faq[] = ['frage' => trim((string) $eintrag['frage']), 'antwort' => trim((string) $eintrag['antwort'])];
            }
        }

        return new StadtSeite(
            stadt: $stadt,
            titel: trim((string) $fm['titel']),
            beschreibung: trim((string) $fm['beschreibung']),
            stand: (string) self::datumsWert($fm['stand']),
            freigegeben: strtolower(trim((string) $fm['freigabe'])) === 'ja',
            einleitungHtml: trim($einleitung),
            inhaltHtml: trim($inhalt),
            faq: $faq,
        );
    }

    /**
     * @param array<string, mixed> $fm
     * @return list<string>
     */
    private function validieren(array $fm, string $basisname, ?Stadt $stadt): array
    {
        $fehler = [];
        foreach (['titel', 'beschreibung', 'stadt', 'slug', 'bundesland', 'stand', 'freigabe'] as $feld) {
            if (!array_key_exists($feld, $fm) || $fm[$feld] === null || $fm[$feld] === '') {
                $fehler[] = sprintf('Pflichtfeld "%s" fehlt.', $feld);
            }
        }
        if ($fehler !== []) {
            return $fehler;
        }
        if ($stadt === null) {
            $fehler[] = sprintf('Dateiname "%s" ist keine Stadt aus config/staedte.php.', $basisname);

            return $fehler;
        }
        if ((string) $fm['slug'] !== $basisname) {
            $fehler[] = sprintf('Feld "slug" (%s) entspricht nicht dem Dateinamen (%s).', (string) $fm['slug'], $basisname);
        }
        if (trim((string) $fm['stadt']) !== $stadt->name) {
            $fehler[] = sprintf('Feld "stadt" (%s) entspricht nicht config/staedte.php (%s).', (string) $fm['stadt'], $stadt->name);
        }
        if (trim((string) $fm['bundesland']) !== $stadt->bundesland) {
            $fehler[] = sprintf('Feld "bundesland" (%s) entspricht nicht config/staedte.php (%s).', (string) $fm['bundesland'], $stadt->bundesland);
        }
        if (self::datumsWert($fm['stand']) === null) {
            $fehler[] = 'Feld "stand" ist kein gültiges Datum (JJJJ-MM-TT).';
        }
        if (!in_array(strtolower(trim((string) $fm['freigabe'])), ['ja', 'nein'], true)) {
            $fehler[] = 'Feld "freigabe" muss "ja" oder "nein" sein.';
        }
        $faq = $fm['faq'] ?? [];
        if (!is_array($faq)) {
            $fehler[] = 'Feld "faq" muss eine Liste sein.';
        } else {
            foreach ($faq as $i => $eintrag) {
                if (!is_array($eintrag) || !isset($eintrag['frage'], $eintrag['antwort']) || $eintrag['frage'] === '' || $eintrag['antwort'] === '') {
                    $fehler[] = sprintf('FAQ-Eintrag %d benötigt "frage" und "antwort".', (int) $i + 1);
                }
            }
        }

        return $fehler;
    }

    /** Umlaute für die Sortierung der Bundesländer gleichwertig zu den Grundbuchstaben behandeln. */
    private static function sortierschluessel(string $text): string
    {
        return strtr(mb_strtolower($text), ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss']);
    }

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
