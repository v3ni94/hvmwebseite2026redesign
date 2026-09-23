<?php

declare(strict_types=1);

namespace Hvm\Controller;

use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Http\Router;
use Hvm\Support\Config;
use Hvm\Support\Container;
use Hvm\Support\SitemapProvider;

/**
 * sitemap.xml und robots.txt.
 *
 * Die Sitemap enthält alle öffentlichen statischen Seiten (config/seiten.php, 'sitemap' => true)
 * sowie Adressen aus registrierten SitemapProvider-Klassen (config/app.php, 'sitemap_providers').
 */
final class SeoController
{
    public function __construct(
        private readonly Config $config,
        private readonly Router $router,
        private readonly Container $container,
    ) {
    }

    public function sitemap(Request $request, array $params = []): Response
    {
        $base = rtrim((string) $this->config->get('app.url'), '/');
        $entries = [];

        foreach ($this->router->staticGetRoutes() as $route) {
            if ($route['handler'] !== [PageController::class, 'show']) {
                continue;
            }
            $slug = (string) ($route['defaults']['page'] ?? '');
            if (($this->config->get('seiten.' . $slug . '.sitemap') ?? false) !== true) {
                continue;
            }
            $entries[$route['path']] = $this->lastmod($slug);
        }

        foreach ((array) $this->config->get('app.sitemap_providers', []) as $class) {
            $provider = $this->container->get((string) $class);
            if (!$provider instanceof SitemapProvider) {
                continue;
            }
            foreach ($provider->sitemapUrls() as $url) {
                $entries[$url['loc']] = $url['lastmod'] ?? null;
            }
        }

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach ($entries as $path => $lastmod) {
            $xml->startElement('url');
            $xml->writeElement('loc', $base . $path);
            if (is_string($lastmod) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastmod)) {
                $xml->writeElement('lastmod', $lastmod);
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();

        return Response::xml($xml->outputMemory())->withHeader('Cache-Control', 'public, max-age=3600');
    }

    /**
     * lastmod bevorzugt das Freigabedatum (config/freigaben.php); ohne Freigabe die letzte
     * Änderung der Seitenvorlage (Dateiänderungsdatum), sonst kein lastmod.
     */
    private function lastmod(string $slug): ?string
    {
        $datum = $this->config->get('freigaben.' . $slug . '.datum');
        if (is_string($datum) && $datum !== '') {
            return $datum;
        }
        $template = (string) $this->config->get('app.base_path') . '/templates/pages/' . $slug . '.html.twig';
        $mtime = is_file($template) ? filemtime($template) : false;

        return $mtime !== false ? date('Y-m-d', $mtime) : null;
    }

    public function robots(Request $request, array $params = []): Response
    {
        if ($this->config->get('app.env') === 'production') {
            $base = rtrim((string) $this->config->get('app.url'), '/');
            $body = "User-agent: *\nDisallow: /admin/\n\nSitemap: " . $base . "/sitemap.xml\n";
        } else {
            $body = "User-agent: *\nDisallow: /\n";
        }

        return Response::text($body)->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
