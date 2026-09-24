<?php

declare(strict_types=1);

namespace Hvm\Seo;

/**
 * Findet [ ... ]-Platzhalter in Templates, Inhalten und Konfiguration (docs/architektur.md
 * Abschnitt 8: fehlende Angaben werden mit `placeholder('…')` bzw. `[…]` gekennzeichnet, nie erfunden).
 * Genutzt von bin/check-placeholders.php.
 */
final class PlaceholderScanner
{
    /**
     * Deutsche Kennwörter, die einen offenen Punkt anzeigen. Rein dekorative eckige Klammern
     * (z. B. in Codebeispielen) ohne eines dieser Wörter zählen nicht als Fund.
     *
     * @var list<string>
     */
    private const SCHLUESSELWOERTER = [
        'ergänzen', 'ergaenzen', 'festlegen', 'bestätigen', 'bestaetigen',
        'klären', 'klaeren', 'freigabe', 'prüfen', 'pruefen', 'verifizieren',
    ];

    private const BINAERE_ENDUNGEN = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'ico', 'pdf', 'woff', 'woff2', 'ttf', 'otf', 'zip', 'gz', 'svg'];

    /**
     * @param list<string> $ziele        Dateien oder Verzeichnisse, absolut oder relativ zu $root
     * @param list<string> $freigegeben  Einträge aus config/placeholder-approvals.php: "Datei:Zeile",
     *                                   ein voller Dateipfad oder ein Teilstring des Platzhaltertexts
     * @return list<array{datei: string, zeile: int, text: string, freigegeben: bool}>
     */
    public static function scan(array $ziele, string $root, array $freigegeben = []): array
    {
        $wortMuster = implode('|', array_map(
            static fn (string $w): string => preg_quote($w, '/'),
            self::SCHLUESSELWOERTER
        ));
        // Markdown-Links [Text](/pfad/) sind keine Platzhalter, auch wenn der Linktext ein Schlüsselwort enthält
        $pattern = '/\[([^\[\]\n]{1,200})\](?!\()/u';

        $funde = [];
        foreach (self::dateien($ziele, $root) as $datei) {
            if (in_array(strtolower(pathinfo($datei, PATHINFO_EXTENSION)), self::BINAERE_ENDUNGEN, true)) {
                continue;
            }
            $inhalt = (string) file_get_contents($datei);
            if (str_contains($inhalt, "\0") || !mb_check_encoding($inhalt, 'UTF-8')) {
                continue;
            }
            $relativ = str_starts_with($datei, $root . '/') ? substr($datei, strlen($root) + 1) : $datei;
            foreach (explode("\n", $inhalt) as $index => $zeile) {
                if (!preg_match_all($pattern, $zeile, $treffer)) {
                    continue;
                }
                foreach ($treffer[1] as $text) {
                    if (!preg_match('/(' . $wortMuster . ')/ui', $text)) {
                        continue;
                    }
                    $fund = ['datei' => $relativ, 'zeile' => $index + 1, 'text' => trim($text)];
                    $fund['freigegeben'] = self::istFreigegeben($fund, $freigegeben);
                    $funde[] = $fund;
                }
            }
        }
        usort($funde, static fn (array $a, array $b): int => [$a['datei'], $a['zeile']] <=> [$b['datei'], $b['zeile']]);

        return $funde;
    }

    /**
     * @param array{datei: string, zeile: int, text: string} $fund
     * @param list<string>                                   $freigegeben
     */
    public static function istFreigegeben(array $fund, array $freigegeben): bool
    {
        $stelle = $fund['datei'] . ':' . $fund['zeile'];
        foreach ($freigegeben as $eintrag) {
            if ($eintrag === $stelle || $eintrag === $fund['datei'] || str_contains($fund['text'], $eintrag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $ziele
     * @return list<string> absolute Dateipfade
     */
    private static function dateien(array $ziele, string $root): array
    {
        $dateien = [];
        foreach ($ziele as $ziel) {
            $absolut = str_starts_with($ziel, '/') ? $ziel : $root . '/' . $ziel;
            if (is_file($absolut)) {
                $dateien[] = $absolut;
                continue;
            }
            if (!is_dir($absolut)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolut, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $dateien[] = $file->getPathname();
                }
            }
        }

        return $dateien;
    }
}
