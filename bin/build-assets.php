<?php

declare(strict_types=1);

/*
 * Asset-Build ohne Node.
 *
 * CSS: resources/css/*.css in sortierter Reihenfolge zu public/assets/build/app.[hash].css,
 *      Kommentare entfernt und Leerraum konservativ reduziert (Zeichenketten bleiben unverändert).
 *      Unterordner resources/css/<name>/*.css ergeben eigene Bundles <name>.[hash].css (z. B. admin.css).
 * JS:  resources/js/** wird unverändert in ein Verzeichnis mit Inhalts-Hash kopiert
 *      (public/assets/build/js/[hash]/...). Relative ES-Modul-Importe funktionieren dadurch weiter,
 *      alle Dateien sind per Hash versioniert und dürfen dauerhaft gecacht werden.
 *      Einstiege: app.js, angebot.js, admin.js (fehlende werden als leeres Modul erzeugt).
 * Manifest: public/assets/build/manifest.json, z. B. {"app.css": "app.1a2b3c4d5e.css", "app.js": "js/9f8e7d6c5b/app.js"}.
 * Kompression: zu CSS, JS, JSON und SVG (Build und public/assets/img) entstehen .gz-Dateien für gzip_static
 *      in Nginx, .br nur bei geladener PHP-Erweiterung brotli (Hvm\Support\Precompress).
 * Atomar: gebaut wird in ein temporäres Verzeichnis, das erst am Ende das bisherige ersetzt. Ein laufender
 *      Server liefert damit bis zum Tausch die alten Assets aus, ein abgebrochener Build lässt sie unberührt.
 *
 * Aufruf: php bin/build-assets.php [--quiet]
 */

use Hvm\Support\Precompress;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$quiet = in_array('--quiet', $argv, true);
$final = $root . '/public/assets/build';
$out = $final . '.neu-' . getmypid();
$cssDir = $root . '/resources/css';
$jsDir = $root . '/resources/js';
$entries = ['app', 'angebot', 'admin'];

$say = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, $message . PHP_EOL);
    }
};

/**
 * Entfernt /* Kommentare * / und reduziert Leerraum außerhalb von Zeichenketten.
 */
function minifyCss(string $css): string
{
    // Zerlegung in Zeichenketten (unverändert) und übrigen Code (Kommentare entfernen, Leerraum reduzieren)
    $segments = [];
    $code = '';
    $length = strlen($css);
    $i = 0;
    while ($i < $length) {
        $char = $css[$i];
        if ($char === '"' || $char === "'") {
            $end = $i + 1;
            while ($end < $length && $css[$end] !== $char) {
                if ($css[$end] === '\\') {
                    $end++;
                }
                $end++;
            }
            $segments[] = [false, $code];
            $segments[] = [true, substr($css, $i, $end - $i + 1)];
            $code = '';
            $i = $end + 1;
            continue;
        }
        if ($char === '/' && ($css[$i + 1] ?? '') === '*') {
            $end = strpos($css, '*/', $i + 2);
            $i = $end === false ? $length : $end + 2;
            $code .= ' ';
            continue;
        }
        $code .= $char;
        $i++;
    }
    $segments[] = [false, $code];

    $result = '';
    foreach ($segments as [$isString, $text]) {
        if ($isString) {
            $result .= $text;
            continue;
        }
        $text = (string) preg_replace('/\s+/', ' ', $text);
        // Leerraum nur um eindeutige Trennzeichen entfernen. Nicht bei ":" (Selektoren wie "a :hover")
        // und nicht bei Operatoren in calc().
        $text = (string) preg_replace('/\s*([{};,>])\s*/', '$1', $text);
        $result .= $text;
    }

    return trim(str_replace(';}', '}', $result));
}

function removeDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

/**
 * @return list<string> relative Pfade aller .js/.mjs-Dateien, sortiert
 */
function listJs(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && preg_match('/\.(m?js|json)$/', $file->getFilename())) {
            $files[] = substr($file->getPathname(), strlen($dir) + 1);
        }
    }
    sort($files);

    return $files;
}

// Reste abgebrochener Läufe entfernen
foreach (glob($final . '.{neu,alt}-*', GLOB_BRACE | GLOB_ONLYDIR) ?: [] as $rest) {
    removeDirectory($rest);
}
if (!mkdir($out, 0775, true) && !is_dir($out)) {
    fwrite(STDERR, "Build-Verzeichnis kann nicht angelegt werden.\n");
    exit(1);
}

$manifest = [];

// CSS
$cssFiles = is_dir($cssDir) ? (glob($cssDir . '/*.css') ?: []) : [];
sort($cssFiles, SORT_STRING);
$css = '';
foreach ($cssFiles as $file) {
    $content = (string) file_get_contents($file);
    if (preg_match('/@import\s/i', $content)) {
        fwrite(STDERR, sprintf("Warnung: @import in %s wird nicht aufgelöst.\n", basename($file)));
    }
    $css .= $content . "\n";
}
$css = minifyCss($css);
$cssName = 'app.' . substr(hash('sha256', $css), 0, 10) . '.css';
file_put_contents($out . '/' . $cssName, $css);
$manifest['app.css'] = $cssName;
$say(sprintf('CSS: %d Dateien, %d Byte, %s', count($cssFiles), strlen($css), $cssName));

// Weitere CSS-Bundles aus Unterordnern: resources/css/<name>/*.css => <name>.[hash].css (z. B. admin.css)
foreach (is_dir($cssDir) ? (glob($cssDir . '/*', GLOB_ONLYDIR) ?: []) : [] as $bundleDir) {
    $bundle = basename($bundleDir);
    if (!preg_match('/^[a-z0-9-]+$/', $bundle) || $bundle === 'app') {
        continue;
    }
    $bundleFiles = glob($bundleDir . '/*.css') ?: [];
    sort($bundleFiles, SORT_STRING);
    $bundleCss = '';
    foreach ($bundleFiles as $file) {
        $bundleCss .= file_get_contents($file) . "\n";
    }
    $bundleCss = minifyCss($bundleCss);
    $bundleName = $bundle . '.' . substr(hash('sha256', $bundleCss), 0, 10) . '.css';
    file_put_contents($out . '/' . $bundleName, $bundleCss);
    $manifest[$bundle . '.css'] = $bundleName;
    $say(sprintf('CSS-Bundle %s: %d Dateien, %d Byte, %s', $bundle, count($bundleFiles), strlen($bundleCss), $bundleName));
}

// JS
$jsFiles = listJs($jsDir);
$hashInput = '';
foreach ($jsFiles as $relative) {
    $hashInput .= $relative . "\0" . file_get_contents($jsDir . '/' . $relative) . "\0";
}
foreach ($entries as $entry) {
    if (!in_array($entry . '.js', $jsFiles, true)) {
        $hashInput .= $entry . ".js\0export {};\0";
    }
}
$jsHash = substr(hash('sha256', $hashInput), 0, 10);
$jsOut = $out . '/js/' . $jsHash;
mkdir($jsOut, 0775, true);
foreach ($jsFiles as $relative) {
    $target = $jsOut . '/' . $relative;
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0775, true);
    }
    copy($jsDir . '/' . $relative, $target);
}
foreach ($entries as $entry) {
    if (!is_file($jsOut . '/' . $entry . '.js')) {
        file_put_contents($jsOut . '/' . $entry . '.js', "export {};\n");
    }
    $manifest[$entry . '.js'] = 'js/' . $jsHash . '/' . $entry . '.js';
}
$say(sprintf('JS: %d Dateien, Verzeichnis js/%s', count($jsFiles), $jsHash));

file_put_contents($out . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$komprimiert = Precompress::directory($out, Precompress::EXTENSIONS, ['manifest.json']);
$komprimiert = array_merge($komprimiert, Precompress::directory($root . '/public/assets/img', ['svg']));
$say(sprintf('Kompression: %d Dateien (gzip%s)', count($komprimiert), function_exists('brotli_compress') ? ', brotli' : ''));

// Tausch: altes Verzeichnis beiseite, neues an seine Stelle, altes entfernen
$alt = $final . '.alt-' . getmypid();
if (is_dir($final) && !rename($final, $alt)) {
    removeDirectory($out);
    fwrite(STDERR, "Bisheriges Build-Verzeichnis kann nicht ersetzt werden.\n");
    exit(1);
}
if (!rename($out, $final)) {
    if (is_dir($alt)) {
        rename($alt, $final);
    }
    removeDirectory($out);
    fwrite(STDERR, "Neues Build-Verzeichnis kann nicht aktiviert werden.\n");
    exit(1);
}
removeDirectory($alt);
$say('Manifest: public/assets/build/manifest.json');
exit(0);
