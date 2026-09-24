<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Content;

use Hvm\Content\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    public function testSlugTransliteratesUppercaseUmlauts(): void
    {
        self::assertSame('uebergabe-der-unterlagen', MarkdownRenderer::slug('Übergabe der Unterlagen'));
        self::assertSame('aenderung', MarkdownRenderer::slug('Änderung'));
        self::assertSame('oeffentliche-mittel', MarkdownRenderer::slug('Öffentliche Mittel'));
        self::assertSame('strasse-und-hausnummer', MarkdownRenderer::slug('Straße und Hausnummer'));
        self::assertSame('abschnitt', MarkdownRenderer::slug('§ …'));
    }

    public function testHeadingIdsAreUniqueAndTransliterated(): void
    {
        $html = (new MarkdownRenderer())->render("## Übergabe\n\nText\n\n## Übergabe\n")['html'];
        self::assertStringContainsString('<h2 id="uebergabe">', $html);
        self::assertStringContainsString('<h2 id="uebergabe-2">', $html);
    }
}
