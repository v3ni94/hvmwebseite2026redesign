<?php

declare(strict_types=1);

namespace Hvm\Content;

/**
 * FAQ einer Zielgruppe (content/faq/{zielgruppe}.yaml).
 */
final class FaqGruppe
{
    /**
     * @param list<FaqFrage> $fragen
     */
    public function __construct(
        public readonly string $zielgruppe,
        public readonly string $titel,
        public readonly array $fragen,
    ) {
    }

    /**
     * @return list<FaqFrage>
     */
    public function veroeffentlichte(): array
    {
        return array_values(array_filter($this->fragen, static fn (FaqFrage $f): bool => $f->freigegeben));
    }
}
