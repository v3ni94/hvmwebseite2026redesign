<?php

declare(strict_types=1);

namespace Hvm\Content;

/**
 * Text einer Stadtseite (content/staedte/{slug}.md), validiert und zu HTML gerendert.
 * einleitungHtml: alles vor der ersten H2, inhaltHtml: ab der ersten H2.
 */
final class StadtSeite
{
    /**
     * @param list<array{frage: string, antwort: string}> $faq
     */
    public function __construct(
        public readonly Stadt $stadt,
        public readonly string $titel,
        public readonly string $beschreibung,
        public readonly string $stand,
        public readonly bool $freigegeben,
        public readonly string $einleitungHtml,
        public readonly string $inhaltHtml,
        public readonly array $faq,
    ) {
    }
}
