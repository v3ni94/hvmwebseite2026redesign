<?php

declare(strict_types=1);

namespace Hvm\View;

use Hvm\Http\Middleware\Csrf;
use Hvm\Http\RequestContext;
use Hvm\Http\Response;
use Hvm\Support\Config;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Twig-Setup: Auto-Escaping html, Cache in storage/cache/twig,
 * auto_reload und strict_variables außerhalb der Produktion.
 */
final class View
{
    private Environment $twig;

    public function __construct(
        private readonly Config $config,
        private readonly RequestContext $context,
        Csrf $csrf,
        string $templatesDir,
        string $cacheDir,
        string $manifestPath,
    ) {
        $production = $config->get('app.env') === 'production';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        $this->twig = new Environment(new FilesystemLoader($templatesDir), [
            'cache' => is_dir($cacheDir) && is_writable($cacheDir) ? $cacheDir : false,
            'auto_reload' => !$production,
            'strict_variables' => !$production,
            'autoescape' => 'html',
            'debug' => false,
            'charset' => 'UTF-8',
        ]);
        $this->twig->addExtension(new TwigExtension($context, $csrf, $manifestPath, $production));

        $this->twig->addGlobal('app', [
            'env' => (string) $config->get('app.env'),
            'url' => (string) $config->get('app.url'),
            'is_production' => $production,
            'show_drafts' => (bool) $config->get('app.show_drafts', false),
            'price_indication' => (bool) $config->get('app.price_indication', false),
        ]);
        $this->twig->addGlobal('firma', $config->array('unternehmen'));
        $this->twig->addGlobal('kennzahlen', $config->array('kennzahlen'));
        $this->twig->addGlobal('nav', $config->array('navigation'));
    }

    public function twig(): Environment
    {
        return $this->twig;
    }

    public function exists(string $template): bool
    {
        return $this->twig->getLoader()->exists($template);
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function render(string $template, array $vars = []): string
    {
        $request = $this->context->request();
        $vars['request'] ??= [
            'path' => $request?->path() ?? '/',
            'query' => $request?->query() ?? [],
        ];

        return $this->twig->render($template, $vars);
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function response(string $template, array $vars = [], int $status = 200): Response
    {
        return Response::html($this->render($template, $vars), $status);
    }
}
