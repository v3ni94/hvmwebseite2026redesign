<?php

declare(strict_types=1);

namespace Hvm\Content;

/**
 * Eine einzelne FAQ-Frage aus content/faq/{zielgruppe}.yaml.
 */
final class FaqFrage
{
    public function __construct(
        public readonly string $frage,
        public readonly string $antwort,
        public readonly bool $freigegeben,
        public readonly ?string $artikel,
    ) {
    }
}
