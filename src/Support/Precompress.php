<?php

declare(strict_types=1);

namespace Hvm\Support;

/**
 * Vorkomprimierte Varianten statischer Dateien für Nginx (gzip_static, optional brotli_static).
 *
 * Neben datei.css entstehen datei.css.gz (gzip, Stufe 9) und, nur wenn die PHP-Erweiterung brotli
 * geladen ist, datei.css.br. Lohnt sich die Kompression nicht (Ergebnis nicht kleiner), wird keine
 * Variante angelegt und eine veraltete entfernt, damit Nginx nie eine alte Fassung ausliefert.
 */
final class Precompress
{
    public const EXTENSIONS = ['css', 'js', 'mjs', 'json', 'svg'];

    /**
     * @return list<string> geschriebene Dateien
     */
    public static function file(string $path): array
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            return [];
        }
        $written = [];
        $variants = ['gz' => static fn (string $d): string|false => gzencode($d, 9, FORCE_GZIP)];
        if (function_exists('brotli_compress')) {
            $variants['br'] = static fn (string $d): string|false => brotli_compress($d, 11);
        } elseif (is_file($path . '.br')) {
            @unlink($path . '.br');
        }
        foreach ($variants as $suffix => $compress) {
            $target = $path . '.' . $suffix;
            $packed = $compress($data);
            if ($packed === false || strlen($packed) >= strlen($data)) {
                if (is_file($target)) {
                    @unlink($target);
                }
                continue;
            }
            if (file_put_contents($target, $packed) !== false) {
                @touch($target, (int) filemtime($path));
                $written[] = $target;
            }
        }

        return $written;
    }

    /**
     * Alle passenden Dateien eines Verzeichnisses (rekursiv).
     *
     * @param list<string> $extensions
     * @param list<string> $skip Dateinamen, die ausgelassen werden (z. B. manifest.json)
     * @return list<string> geschriebene Dateien
     */
    public static function directory(string $dir, array $extensions = self::EXTENSIONS, array $skip = []): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $written = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || in_array($file->getFilename(), $skip, true)) {
                continue;
            }
            if (in_array(strtolower($file->getExtension()), $extensions, true)) {
                array_push($written, ...self::file($file->getPathname()));
            }
        }
        sort($written);

        return $written;
    }
}
