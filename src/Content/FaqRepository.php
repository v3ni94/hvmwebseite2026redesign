<?php

declare(strict_types=1);

namespace Hvm\Content;

use Hvm\Support\Config;
use Hvm\Support\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * Liest content/faq/{zielgruppe}.yaml (Format docs/wissen-inhaltsplan.md Abschnitt 3).
 * Gleiche Freigabelogik wie WissenRepository: veröffentlicht wird je Frage nur mit
 * `freigabe: ja`, Entwürfe zusätzlich nur außerhalb der Produktion mit SHOW_DRAFTS=true.
 */
final class FaqRepository
{
    private readonly string $verzeichnis;

    /** @var list<FaqGruppe>|null */
    private ?array $alle = null;

    /** @var array<string, list<string>> */
    private array $fehler = [];

    public function __construct(
        private readonly Config $config,
        private readonly Log $log,
        ?string $verzeichnis = null,
    ) {
        $this->verzeichnis = rtrim($verzeichnis ?? ((string) $config->get('app.base_path') . '/content/faq'), '/');
    }

    public function zeigeEntwuerfe(): bool
    {
        if ($this->config->get('app.env') === 'production') {
            return false;
        }

        return (bool) $this->config->get('app.show_drafts', false);
    }

    /**
     * @return list<FaqGruppe> Nur Gruppen mit mindestens einer (im aktuellen Modus) sichtbaren Frage.
     */
    public function alleGruppen(): array
    {
        $zeigeEntwuerfe = $this->zeigeEntwuerfe();
        $gruppen = [];
        foreach ($this->laden() as $gruppe) {
            $fragen = array_values(array_filter(
                $gruppe->fragen,
                static fn (FaqFrage $f): bool => $f->freigegeben || $zeigeEntwuerfe
            ));
            if ($fragen !== []) {
                $gruppen[] = new FaqGruppe($gruppe->zielgruppe, $gruppe->titel, $fragen);
            }
        }

        return $gruppen;
    }

    public function gruppeFuer(string $zielgruppe): ?FaqGruppe
    {
        foreach ($this->alleGruppen() as $gruppe) {
            if ($gruppe->zielgruppe === $zielgruppe) {
                return $gruppe;
            }
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function fehlermeldungen(): array
    {
        $this->laden();

        return $this->fehler;
    }

    /**
     * @return list<FaqGruppe> ungefiltert nach Freigabe, für interne Weiterverarbeitung
     */
    private function laden(): array
    {
        if ($this->alle !== null) {
            return $this->alle;
        }

        $gruppen = [];
        $this->fehler = [];
        foreach (glob($this->verzeichnis . '/*.yaml') ?: [] as $datei) {
            $basisname = basename($datei);
            try {
                $gruppe = $this->ladenDatei($datei, $basisname);
                if ($gruppe !== null) {
                    $gruppen[] = $gruppe;
                }
            } catch (\Throwable $e) {
                $this->fehler[$basisname] = [$e->getMessage()];
                $this->log->warning('FAQ-Datei konnte nicht gelesen werden.', ['datei' => $basisname]);
            }
        }

        return $this->alle = $gruppen;
    }

    private function ladenDatei(string $datei, string $basisname): ?FaqGruppe
    {
        $daten = Yaml::parseFile($datei);
        if (!is_array($daten)) {
            $this->fehler[$basisname] = ['Datei enthält kein YAML-Array.'];

            return null;
        }

        $fehler = $this->validieren($daten);
        if ($fehler !== []) {
            $this->fehler[$basisname] = $fehler;
            $this->log->warning('FAQ-Datei mit ungültigem Inhalt übersprungen.', ['datei' => $basisname, 'anzahl_fehler' => count($fehler)]);

            return null;
        }

        $fragen = [];
        foreach ((array) $daten['fragen'] as $eintrag) {
            $fragen[] = new FaqFrage(
                frage: (string) $eintrag['frage'],
                antwort: (string) $eintrag['antwort'],
                freigegeben: strtolower(trim((string) $eintrag['freigabe'])) === 'ja',
                artikel: isset($eintrag['artikel']) && $eintrag['artikel'] !== '' ? (string) $eintrag['artikel'] : null,
            );
        }

        return new FaqGruppe(
            zielgruppe: (string) $daten['zielgruppe'],
            titel: (string) $daten['titel'],
            fragen: $fragen,
        );
    }

    /**
     * @param array<string, mixed> $daten
     * @return list<string>
     */
    private function validieren(array $daten): array
    {
        $fehler = [];
        foreach (['zielgruppe', 'titel', 'fragen'] as $feld) {
            if (!array_key_exists($feld, $daten) || $daten[$feld] === null || $daten[$feld] === '') {
                $fehler[] = sprintf('Pflichtfeld "%s" fehlt.', $feld);
            }
        }
        if ($fehler !== []) {
            return $fehler;
        }
        if (!in_array((string) $daten['zielgruppe'], Article::zielgruppen(), true)) {
            $fehler[] = sprintf('Feld "zielgruppe" (%s) ist ungültig.', (string) $daten['zielgruppe']);
        }
        if (!is_array($daten['fragen']) || $daten['fragen'] === []) {
            $fehler[] = 'Feld "fragen" muss eine nicht leere Liste sein.';

            return $fehler;
        }
        foreach ($daten['fragen'] as $i => $eintrag) {
            if (!is_array($eintrag)) {
                $fehler[] = sprintf('Frage %d ist kein Objekt.', $i + 1);
                continue;
            }
            foreach (['frage', 'antwort', 'freigabe'] as $feld) {
                if (!array_key_exists($feld, $eintrag) || $eintrag[$feld] === null || $eintrag[$feld] === '') {
                    $fehler[] = sprintf('Frage %d: Pflichtfeld "%s" fehlt.', $i + 1, $feld);
                }
            }
            if (isset($eintrag['freigabe']) && !in_array(strtolower(trim((string) $eintrag['freigabe'])), ['ja', 'nein'], true)) {
                $fehler[] = sprintf('Frage %d: Feld "freigabe" muss "ja" oder "nein" sein.', $i + 1);
            }
        }

        return $fehler;
    }
}
