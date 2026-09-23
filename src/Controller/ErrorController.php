<?php

declare(strict_types=1);

namespace Hvm\Controller;

use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\View\PageMeta;
use Hvm\View\View;

final class ErrorController
{
    public function __construct(
        private readonly View $view,
        private readonly PageMeta $meta,
    ) {
    }

    public function notFound(Request $request): Response
    {
        $page = $this->meta->build('404', $request->path());
        // Fehlerseiten tragen keinen Canonical auf die angefragte Adresse und keine strukturierten Daten
        $page['canonical'] = '';
        $page['schema'] = [];
        $page['breadcrumbs'] = [];

        return $this->view->response('pages/404.html.twig', ['page' => $page], 404)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * 500-Seite ohne Details. Wird vom ErrorHandler in Produktion genutzt.
     */
    public function renderServerError(Request $request): ?string
    {
        if (!$this->view->exists('pages/500.html.twig')) {
            return null;
        }
        $page = $this->meta->build('500', $request->path());
        $page['canonical'] = '';
        $page['schema'] = [];
        $page['breadcrumbs'] = [];

        return $this->view->render('pages/500.html.twig', ['page' => $page]);
    }
}
