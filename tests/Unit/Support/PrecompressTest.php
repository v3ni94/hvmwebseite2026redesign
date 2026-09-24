<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Support;

use Hvm\Support\Precompress;
use PHPUnit\Framework\TestCase;

final class PrecompressTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/hvm-precompress-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/js/abc', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    public function testWritesGzipThatDecodesToOriginal(): void
    {
        $css = str_repeat('.c-button{color:#1a1a1a}', 200);
        file_put_contents($this->dir . '/app.css', $css);
        file_put_contents($this->dir . '/js/abc/app.js', str_repeat('export const a = 1;', 100));
        file_put_contents($this->dir . '/manifest.json', str_repeat('{"a":"b"}', 100));
        file_put_contents($this->dir . '/bild.png', str_repeat('x', 5000));

        $written = Precompress::directory($this->dir, Precompress::EXTENSIONS, ['manifest.json']);

        self::assertContains($this->dir . '/app.css.gz', $written);
        self::assertContains($this->dir . '/js/abc/app.js.gz', $written);
        self::assertFileDoesNotExist($this->dir . '/manifest.json.gz');
        self::assertFileDoesNotExist($this->dir . '/bild.png.gz');
        self::assertSame($css, gzdecode((string) file_get_contents($this->dir . '/app.css.gz')));
        self::assertSame(function_exists('brotli_compress'), is_file($this->dir . '/app.css.br'), '.br nur mit Erweiterung brotli');
    }

    public function testRemovesStaleVariantWhenCompressionDoesNotPay(): void
    {
        file_put_contents($this->dir . '/klein.svg', '<svg/>');
        file_put_contents($this->dir . '/klein.svg.gz', 'veraltet');
        self::assertSame([], Precompress::file($this->dir . '/klein.svg'));
        self::assertFileDoesNotExist($this->dir . '/klein.svg.gz');
    }
}
