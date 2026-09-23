<?php

declare(strict_types=1);

namespace Hvm\View;

use Hvm\Http\RequestContext;
use Hvm\Service\Attribution;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig-Funktion cta_url(): CTA-Links, die die Kanalzuordnung ohne Cookies weiterreichen.
 *
 *   <a href="{{ cta_url('/angebot/', {art: 'weg'}) }}">Angebot anfordern</a>
 *
 * Ergänzt vorhandene utm_* und gclid der aktuellen Anfrage, den Landing-Pfad (lp) und den Host eines
 * fremden Referrers (ref). Explizit übergebene Parameter haben Vorrang. Das Ergebnis ist eine
 * normale Zeichenkette und wird von Twig im Attribut escaped.
 */
final class AttributionExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly string $appUrl = '',
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cta_url', $this->ctaUrl(...)),
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    public function ctaUrl(string $path, array $params = []): string
    {
        $request = $this->context->request();
        if ($request === null) {
            return Attribution::buildUrl($path, $params);
        }
        $forward = Attribution::forwardParams(
            $request->query(),
            $request->path(),
            $request->header('Referer'),
            $this->ownHosts($request->header('Host'))
        );

        return Attribution::buildUrl($path, array_merge($forward, $params));
    }

    /**
     * @return list<string>
     */
    private function ownHosts(?string $requestHost): array
    {
        $hosts = [];
        $appHost = parse_url($this->appUrl, PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $hosts[] = $appHost;
        }
        if ($requestHost !== null && $requestHost !== '') {
            $hosts[] = $requestHost;
        }

        return $hosts;
    }
}
