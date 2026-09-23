<?php

declare(strict_types=1);

/*
 * Prüft Texte auf Gedankenstriche und verwandte Zeichen (U+2012 bis U+2015, U+2212).
 * Geprüft: templates/, content/, config/, docs/, resources/ und README.md. Ausnahme: docs/quellen/.
 * Ausgabe Datei:Zeile, Exitcode 1 bei Treffern.
 *
 * Aufruf: php bin/lint-dashes.php [Pfad ...]
 */

$root = dirname(__DIR__);
$targets = array_slice($argv, 1);
if ($targets === []) {
    $targets = ['templates', 'content', 'config', 'docs', 'resources', 'README.md'];
}
$excluded = ['docs/quellen/'];
$binaryExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'ico', 'pdf', 'woff', 'woff2', 'ttf', 'otf', 'zip', 'gz'];
$pattern = '/[\x{2012}-\x{2015}\x{2212}]/u';
$names = [0x2012 => 'U+2012 Ziffernstrich', 0x2013 => 'U+2013 Halbgeviertstrich', 0x2014 => 'U+2014 Geviertstrich', 0x2015 => 'U+2015 Horizontalstrich', 0x2212 => 'U+2212 Minuszeichen'];

$files = [];
foreach ($targets as $target) {
    $path = str_starts_with($target, '/') ? $target : $root . '/' . $target;
    if (is_file($path)) {
        $files[] = $path;
        continue;
    }
    if (!is_dir($path)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);

$hits = 0;
foreach ($files as $file) {
    $relative = str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
    foreach ($excluded as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            continue 2;
        }
    }
    if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $binaryExtensions, true)) {
        continue;
    }
    $content = (string) file_get_contents($file);
    if (str_contains($content, "\0") || !mb_check_encoding($content, 'UTF-8')) {
        continue;
    }
    foreach (explode("\n", $content) as $index => $line) {
        if (!preg_match_all($pattern, $line, $matches)) {
            continue;
        }
        foreach (array_unique($matches[0]) as $char) {
            $hits++;
            fwrite(STDOUT, sprintf("%s:%d: %s\n", $relative, $index + 1, $names[mb_ord($char)] ?? 'Strich'));
        }
    }
}

if ($hits > 0) {
    fwrite(STDERR, sprintf("%d Treffer. Gedankenstriche durch Komma, Punkt oder Umformulierung ersetzen, Bereiche als \"9 bis 18 Uhr\".\n", $hits));
    exit(1);
}
fwrite(STDOUT, sprintf("Keine Gedankenstriche gefunden (%d Dateien geprüft).\n", count($files)));
exit(0);
