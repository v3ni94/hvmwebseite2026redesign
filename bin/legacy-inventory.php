<?php

declare(strict_types=1);

/*
 * Erzeugt aus dem bereinigten Mirror (siehe bin/legacy-scrub.php) das Inventar der Altseite
 * (MP Abschnitt 13, Phase 1): URL-Liste, Seitentexte je URL (optional), Bildinventar,
 * Formularfelder und eingebundene Drittdienste. Ergebnisse ohne personenbezogene Daten.
 *
 * Voraussetzung: bin/legacy-scrub.php ist auf den Mirror bereits angewendet. Dieses Skript
 * prueft nicht erneut auf personenbezogene Daten, es liest nur, was der Mirror an dieser
 * Stelle noch enthaelt.
 *
 * Aufruf: php bin/legacy-inventory.php --path=/pfad/zum/mirror [--domain=muellerhv.de] [--texte]
 *
 * Ausgabe (docs/legacy/):
 * - urls.md               vollstaendige URL-Liste
 * - bildinventar.md        Tabelle Datei, Groesse, Abmessungen, Verwendung, Alt-Text, Lizenzstatus
 * - formulare.md           Formularfelder je Seite
 * - drittdienste.md        Script- und iframe-Hosts
 * - texte/<pfad>.txt       Seitentexte je URL, nur mit --texte
 */

$root = dirname(__DIR__);

$pfad = null;
$domain = 'muellerhv.de';
$mitTexten = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--path=')) {
        $pfad = rtrim(substr($arg, strlen('--path=')), '/');
    } elseif (str_starts_with($arg, '--domain=')) {
        $domain = substr($arg, strlen('--domain='));
    } elseif ($arg === '--texte') {
        $mitTexten = true;
    }
}
if ($pfad === null || $pfad === '' || !is_dir($pfad)) {
    fwrite(STDERR, "Aufruf: php bin/legacy-inventory.php --path=/pfad/zum/mirror [--domain=muellerhv.de] [--texte]\n");
    exit(2);
}

$ausgabeVerzeichnis = $root . '/docs/legacy';
if (!is_dir($ausgabeVerzeichnis) && !mkdir($ausgabeVerzeichnis, 0775, true) && !is_dir($ausgabeVerzeichnis)) {
    fwrite(STDERR, sprintf("Konnte Verzeichnis nicht anlegen: %s\n", $ausgabeVerzeichnis));
    exit(2);
}

/**
 * @return list<string> alle Dateien unterhalb von $pfad
 */
function alleDateien(string $pfad): array
{
    $treffer = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pfad, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $datei) {
        /** @var SplFileInfo $datei */
        if ($datei->isFile()) {
            $treffer[] = $datei->getPathname();
        }
    }
    sort($treffer);

    return $treffer;
}

/**
 * Bildet aus dem lokalen Mirror-Pfad die urspruengliche URL nach (wget --no-host-directories:
 * Mirror-Wurzel entspricht dem Domain-Wurzelverzeichnis).
 */
function pfadZuUrl(string $datei, string $wurzel, string $domain): string
{
    $relativ = ltrim(substr($datei, strlen($wurzel)), '/');

    return 'https://' . $domain . '/' . $relativ;
}

function istHtml(string $datei): bool
{
    return in_array(strtolower(pathinfo($datei, PATHINFO_EXTENSION)), ['html', 'htm'], true);
}

function istBild(string $datei): bool
{
    return in_array(strtolower(pathinfo($datei, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg'], true);
}

/**
 * @return array{0: int, 1: int} Breite, Hoehe (0, 0 wenn nicht ermittelbar, z. B. SVG)
 */
function bildAbmessungen(string $datei): array
{
    $info = @getimagesize($datei);
    if ($info === false) {
        return [0, 0];
    }

    return [(int) $info[0], (int) $info[1]];
}

function formatiereGroesse(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return sprintf('%.1f MB', $bytes / (1024 * 1024));
    }
    if ($bytes >= 1024) {
        return sprintf('%.0f KB', $bytes / 1024);
    }

    return $bytes . ' B';
}

/**
 * @return list<string> Text-Zeilen ohne Tags, Skripte und Styles
 */
function extrahiereText(string $html): array
{
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
    $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html) ?? $html;
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $zeilen = preg_split('/\r?\n/', $text) ?: [];
    $ergebnis = [];
    foreach ($zeilen as $zeile) {
        $zeile = trim(preg_replace('/[ \t]+/', ' ', $zeile) ?? '');
        if ($zeile !== '') {
            $ergebnis[] = $zeile;
        }
    }

    return $ergebnis;
}

/**
 * @return list<array{tag: string, name: string, typ: string, pflicht: bool}>
 */
function extrahiereFormularfelder(string $html): array
{
    $felder = [];
    if (preg_match_all('/<(input|select|textarea)\b([^>]*)>/i', $html, $treffer, PREG_SET_ORDER)) {
        foreach ($treffer as $treffer_) {
            $tag = strtolower($treffer_[1]);
            $attribute = $treffer_[2];
            preg_match('/\bname=["\']([^"\']+)["\']/i', $attribute, $nameTreffer);
            preg_match('/\btype=["\']([^"\']+)["\']/i', $attribute, $typTreffer);
            $name = $nameTreffer[1] ?? '';
            if ($name === '' || str_starts_with($name, '_csrf') || strtolower($name) === 'honeypot') {
                continue;
            }
            $felder[] = [
                'tag' => $tag,
                'name' => $name,
                'typ' => $tag === 'input' ? strtolower($typTreffer[1] ?? 'text') : $tag,
                'pflicht' => (bool) preg_match('/\brequired\b/i', $attribute),
            ];
        }
    }

    return $felder;
}

/**
 * @return list<string> Hosts aus src von script und iframe, ohne die eigene Domain
 */
function extrahiereDrittdienste(string $html, string $domain): array
{
    $hosts = [];
    if (preg_match_all('/<(?:script|iframe)\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $html, $treffer)) {
        foreach ($treffer[1] as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '' && !str_ends_with($host, $domain)) {
                $hosts[] = strtolower($host);
            }
        }
    }

    return array_values(array_unique($hosts));
}

$dateien = alleDateien($pfad);

$urls = [];
$bildZeilen = [];
$formularZeilen = [];
$drittdiensteHosts = [];
$texteGeschrieben = 0;

foreach ($dateien as $datei) {
    $url = pfadZuUrl($datei, $pfad, $domain);
    $urls[] = $url;

    if (istBild($datei)) {
        [$breite, $hoehe] = bildAbmessungen($datei);
        $groesse = @filesize($datei);
        $bildZeilen[] = sprintf(
            '| %s | %s | %s | ungeklaert | nein bis geklaert |',
            '`' . ltrim(substr($datei, strlen($pfad)), '/') . '`',
            $groesse !== false ? formatiereGroesse($groesse) : 'unbekannt',
            $breite > 0 ? $breite . ' x ' . $hoehe : 'unbekannt (z. B. SVG)'
        );
        continue;
    }

    if (!istHtml($datei)) {
        continue;
    }

    $html = file_get_contents($datei);
    if ($html === false) {
        continue;
    }

    $felder = extrahiereFormularfelder($html);
    if ($felder !== []) {
        $formularZeilen[] = '### ' . $url;
        foreach ($felder as $feld) {
            $formularZeilen[] = sprintf(
                '- `%s` (%s, %s%s)',
                $feld['name'],
                $feld['tag'],
                $feld['typ'],
                $feld['pflicht'] ? ', Pflichtfeld' : ''
            );
        }
        $formularZeilen[] = '';
    }

    foreach (extrahiereDrittdienste($html, $domain) as $host) {
        $drittdiensteHosts[$host] = ($drittdiensteHosts[$host] ?? 0) + 1;
    }

    if ($mitTexten) {
        $relativerPfad = ltrim(substr($datei, strlen($pfad)), '/');
        $relativerPfad = preg_replace('/\.html?$/i', '', $relativerPfad) ?? $relativerPfad;
        $zielDatei = $root . '/docs/legacy/texte/' . $relativerPfad . '.txt';
        $zielVerzeichnis = dirname($zielDatei);
        if (!is_dir($zielVerzeichnis)) {
            mkdir($zielVerzeichnis, 0775, true);
        }
        file_put_contents($zielDatei, implode("\n", extrahiereText($html)) . "\n");
        $texteGeschrieben++;
    }
}

sort($urls);
file_put_contents(
    $ausgabeVerzeichnis . '/urls.md',
    "# URL-Liste Altseite (Mirror)\n\nErzeugt aus dem bereinigten Mirror, " . count($urls) . " Adressen.\n\n"
    . implode("\n", array_map(static fn (string $u): string => '- ' . $u, $urls)) . "\n"
);

file_put_contents(
    $ausgabeVerzeichnis . '/bildinventar.md',
    "# Bildinventar Altseite (Mirror-Rohdaten)\n\n"
    . "Rohtabelle aus dem Mirror, zur Uebernahme nach `docs/bildinventar.md` (dort mit Lizenzpruefung\n"
    . "und Entscheidung zur Nutzung im Relaunch). Alt-Text und Seitenverwendung sind aus dem\n"
    . "Mirror-Dateisystem allein nicht zuverlaessig ermittelbar und daher hier nicht ausgefuellt.\n\n"
    . "| Datei | Groesse | Abmessungen | Lizenzstatus | Nutzung im Relaunch |\n|---|---|---|---|---|\n"
    . implode("\n", $bildZeilen) . "\n"
);

file_put_contents(
    $ausgabeVerzeichnis . '/formulare.md',
    "# Formularfelder Altseite (Mirror)\n\n" . implode("\n", $formularZeilen) . "\n"
);

$drittdiensteZeilen = [];
arsort($drittdiensteHosts);
foreach ($drittdiensteHosts as $host => $anzahl) {
    $drittdiensteZeilen[] = sprintf('| `%s` | %d |', $host, $anzahl);
}
file_put_contents(
    $ausgabeVerzeichnis . '/drittdienste.md',
    "# Eingebundene Drittdienste Altseite (Mirror)\n\n"
    . "Script- und iframe-Hosts ausserhalb der eigenen Domain, mit Anzahl der Seiten, auf denen\n"
    . "sie vorkommen. Enthaelt keine personenbezogenen Daten.\n\n"
    . "| Host | Seiten |\n|---|---|\n"
    . implode("\n", $drittdiensteZeilen) . "\n"
);

fwrite(STDOUT, sprintf(
    "Inventar erzeugt: %d Adressen, %d Bilder, %d Formulare, %d Drittdienst-Hosts%s.\n",
    count($urls),
    count($bildZeilen),
    substr_count(implode("\n", $formularZeilen), '### '),
    count($drittdiensteHosts),
    $mitTexten ? sprintf(', %d Seitentexte geschrieben', $texteGeschrieben) : ''
));
fwrite(STDOUT, sprintf("Ausgabe unter %s\n", $ausgabeVerzeichnis));
