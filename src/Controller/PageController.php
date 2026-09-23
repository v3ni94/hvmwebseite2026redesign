<?php

declare(strict_types=1);

namespace Hvm\Controller;

use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\View\PageMeta;
use Hvm\View\View;

/**
 * Statische Inhaltsseiten: Template pages/{page}.html.twig, Metadaten aus config/seiten.php.
 */
final class PageController
{
    public function __construct(
        private readonly View $view,
        private readonly PageMeta $meta,
        private readonly ErrorController $errors,
    ) {
    }

    /**
     * @param array<string, mixed> $params Routenparameter, "page" ist der Seitenschlüssel
     */
    public function show(Request $request, array $params = []): Response
    {
        $slug = (string) ($params['page'] ?? '');
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return $this->errors->notFound($request);
        }

        $template = 'pages/' . $slug . '.html.twig';
        if (!$this->view->exists($template)) {
            return $this->errors->notFound($request);
        }

        return $this->view->response($template, [
            'page' => $this->meta->build($slug, $request->path()),
        ]);
    }
}
