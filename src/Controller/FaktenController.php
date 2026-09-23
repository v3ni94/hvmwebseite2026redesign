<?php

declare(strict_types=1);

namespace Hvm\Controller;

use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Support\Config;
use Hvm\View\PageMeta;
use Hvm\View\View;

/**
 * Faktenseite /fakten/ („Die HVM in Zahlen und Fakten“, docs/seo-geo.md Abschnitt 4).
 * Kompakte, zitierfähige Angaben ausschließlich aus config/unternehmen.php, config/kennzahlen.php
 * und config/fakten.php, jeweils mit Stand-Datum. Organisation als strukturierte Daten über PageMeta.
 */
final class FaktenController
{
    public function __construct(
        private readonly View $view,
        private readonly PageMeta $meta,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function show(Request $request, array $params = []): Response
    {
        return $this->view->response('pages/fakten.html.twig', [
            'page' => $this->meta->build('fakten', $request->path()),
            'fakten' => $this->config->array('fakten'),
        ]);
    }
}
