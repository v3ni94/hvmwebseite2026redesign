<?php

declare(strict_types=1);

/*
 * Erzeugt je Seite mit Open-Graph-Bild (config/seiten.php, 'og' !== false) ein 1200 x 630 PNG
 * unter public/og/<slug>.png: weißer Grund, Kennlinie (Architektur/Designsystem Abschnitt 4.1)
 * am oberen Rand mit den vier Segmenten und ihren Schrägen, Seitentitel mit Zeilenumbruch,
 * das Logo als Bilddatei (keine Wortmarke als Text) auf der weißen Fläche unten.
 *
 * Schrift: Liberation Sans (Docker-Image: Paket fonts-liberation), lokal unter
 * /usr/share/fonts/truetype/liberation.
 *
 * Aufruf: php bin/build-og-images.php [--quiet]
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Hvm\Support\Config;

$quiet = in_array('--quiet', $argv, true);
$say = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, $message . PHP_EOL);
    }
};

if (!extension_loaded('gd')) {
    fwrite(STDERR, "Die PHP-Erweiterung gd wird benötigt.\n");
    exit(1);
}

$fontDir = getenv('OG_FONT_DIR') ?: '/usr/share/fonts/truetype/liberation';
$fontBold = $fontDir . '/LiberationSans-Bold.ttf';
$fontRegular = $fontDir . '/LiberationSans-Regular.ttf';
if (!is_file($fontBold) || !is_file($fontRegular)) {
    fwrite(STDERR, sprintf("Liberation Sans nicht gefunden unter %s (Paket fonts-liberation).\n", $fontDir));
    exit(1);
}

$logoPath = $root . '/public/assets/img/logo/hvm-logo-960.png';
if (!is_file($logoPath)) {
    fwrite(STDERR, sprintf("Logo nicht gefunden: %s\n", $logoPath));
    exit(1);
}

$config = Config::fromDirectory($root . '/config');
$outDir = $root . '/public/og';
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, sprintf("Ausgabeverzeichnis %s kann nicht angelegt werden.\n", $outDir));
    exit(1);
}

/**
 * CI-Farben (docs/designsystem.md Abschnitt 3.1). Keine weiteren Farben.
 */
final class OgFarben
{
    public function __construct(private readonly \GdImage $bild)
    {
    }

    public function orange(): int
    {
        return imagecolorallocate($this->bild, 0xE6, 0xA8, 0x3C);
    }

    public function ink(): int
    {
        return imagecolorallocate($this->bild, 0x1A, 0x1A, 0x1A);
    }

    public function anthrazit(): int
    {
        return imagecolorallocate($this->bild, 0x87, 0x88, 0x8A);
    }

    public function mittelgrau(): int
    {
        return imagecolorallocate($this->bild, 0x9C, 0x9D, 0x9F);
    }

    public function hellgrau(): int
    {
        return imagecolorallocate($this->bild, 0xD7, 0xD8, 0xDA);
    }

    public function weiss(): int
    {
        return imagecolorallocate($this->bild, 0xFF, 0xFF, 0xFF);
    }
}

/**
 * Zeichnet die Kennlinie (docs/designsystem.md 4.1) als Band voller Breite: Anthrazit 0 bis 40 %,
 * Mittelgrau 40 bis 60 %, Orange 60 bis 67,5 %, Hellgrau 67,5 bis 100 %. Die Schräge (0,9 × Bandhöhe)
 * verschiebt jede Segmentgrenze an der Unterkante um diesen Betrag nach rechts gegenüber der Oberkante,
 * exakt wie im CSS-Verlauf der Briefbogen-Kennlinie.
 */
function zeichneKennlinie(\GdImage $bild, int $breite, int $y, int $hoehe, OgFarben $farben): void
{
    $schraege = 0.9 * $hoehe;
    $stopps = [0.0, 0.40, 0.60, 0.675, 1.0];
    $segmentfarben = [$farben->anthrazit(), $farben->mittelgrau(), $farben->orange(), $farben->hellgrau()];

    for ($i = 0; $i < 4; $i++) {
        $obenLinks = $stopps[$i] * $breite;
        $obenRechts = $stopps[$i + 1] * $breite;
        $untenLinks = $obenLinks + $schraege;
        $untenRechts = $obenRechts + $schraege;
        $punkte = [
            $obenLinks, $y,
            $obenRechts, $y,
            $untenRechts, $y + $hoehe,
            $untenLinks, $y + $hoehe,
        ];
        imagefilledpolygon($bild, $punkte, $segmentfarben[$i]);
    }
    // Überstand der letzten Schräge über den rechten Rand mit der letzten Farbe schließen.
    imagefilledrectangle($bild, $breite, $y, $breite + (int) ceil($schraege), $y + $hoehe, end($segmentfarben));
    // Linker Überstand (negative x) entsteht hier nicht, da Segment 0 bei x = 0 beginnt.
}

/**
 * Bricht einen Text in Zeilen um, die mit der gegebenen Schriftgröße nicht breiter als $maxBreite sind.
 *
 * @return list<string>
 */
function zeileUmbrechen(string $text, string $font, float $groesse, int $maxBreite): array
{
    $woerter = preg_split('/\s+/u', trim($text)) ?: [];
    $zeilen = [];
    $aktuell = '';
    foreach ($woerter as $wort) {
        $kandidat = $aktuell === '' ? $wort : $aktuell . ' ' . $wort;
        $box = imagettfbbox($groesse, 0, $font, $kandidat);
        $breite = $box[2] - $box[0];
        if ($breite > $maxBreite && $aktuell !== '') {
            $zeilen[] = $aktuell;
            $aktuell = $wort;
            continue;
        }
        $aktuell = $kandidat;
    }
    if ($aktuell !== '') {
        $zeilen[] = $aktuell;
    }

    return $zeilen;
}

$breite = 1200;
$hoehe = 630;
$erzeugt = 0;

foreach ($config->array('seiten') as $slug => $meta) {
    if (($meta['og'] ?? true) === false) {
        continue;
    }
    $titel = (string) ($meta['titel'] ?? $slug);

    $bild = imagecreatetruecolor($breite, $hoehe);
    imagesavealpha($bild, true);
    imagealphablending($bild, true);
    $farben = new OgFarben($bild);
    imagefilledrectangle($bild, 0, 0, $breite, $hoehe, $farben->weiss());

    // Kennlinie am oberen Rand, 12 px hoch (skaliert für die 1200 px breite Fläche).
    zeichneKennlinie($bild, $breite, 0, 12, $farben);

    // Titel, mit Umbruch, linksbündig im Textbereich.
    $randX = 80;
    $textBreite = $breite - 2 * $randX - 260; // Platz rechts für das Logo freihalten
    $groesse = 58.0;
    $zeilen = zeileUmbrechen($titel, $fontBold, $groesse, $textBreite);
    if (count($zeilen) > 3) {
        $groesse = 44.0;
        $zeilen = zeileUmbrechen($titel, $fontBold, $groesse, $textBreite);
    }
    $zeilenhoehe = (int) round($groesse * 1.2);
    $gesamthoehe = $zeilenhoehe * count($zeilen);
    $startY = (int) ((($hoehe - 90) - $gesamthoehe) / 2) + $zeilenhoehe;
    foreach ($zeilen as $i => $zeile) {
        imagettftext($bild, $groesse, 0, $randX, $startY + $i * $zeilenhoehe, $farben->ink(), $fontBold, $zeile);
    }

    // Firma als schmale Eyebrow-Zeile unter dem Titel, sachlich, ohne Wortmarke als grafisches Element.
    $firma = (string) $config->get('unternehmen.name', '');
    imagettftext($bild, 20.0, 0, $randX, $startY + count($zeilen) * $zeilenhoehe + 36, $farben->anthrazit(), $fontRegular, $firma);

    // Logo unten rechts, auf der weißen Fläche (kein zusätzlicher Schutzrahmen nötig, Grund ist bereits Weiß).
    $logo = imagecreatefrompng($logoPath);
    imagesavealpha($logo, true);
    $logoZielBreite = 220;
    $logoZielHoehe = (int) round(imagesy($logo) * ($logoZielBreite / imagesx($logo)));
    imagecopyresampled(
        $bild,
        $logo,
        $breite - $logoZielBreite - 80,
        $hoehe - $logoZielHoehe - 72,
        0,
        0,
        $logoZielBreite,
        $logoZielHoehe,
        imagesx($logo),
        imagesy($logo)
    );
    imagedestroy($logo);

    $ziel = $outDir . '/' . $slug . '.png';
    imagepng($bild, $ziel, 6);
    imagedestroy($bild);
    $erzeugt++;
    $say('OG-Bild erzeugt: ' . $ziel);
}

$say(sprintf('%d OG-Bilder erzeugt in %s.', $erzeugt, $outDir));
exit(0);
