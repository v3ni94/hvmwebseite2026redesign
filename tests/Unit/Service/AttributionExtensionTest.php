<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Service;

use Hvm\Http\Request;
use Hvm\Http\RequestContext;
use Hvm\View\AttributionExtension;
use PHPUnit\Framework\TestCase;

final class AttributionExtensionTest extends TestCase
{
    private function url(string $uri, string $path, array $params = [], array $server = []): string
    {
        $context = new RequestContext();
        $context->begin(Request::create('GET', $uri, [], $server));

        return (new AttributionExtension($context, 'https://www.muellerhv.de'))->ctaUrl($path, $params);
    }

    public function testForwardsUtmGclidAndLandingPath(): void
    {
        $url = $this->url('/weg-verwaltung/?utm_source=google&utm_medium=cpc&gclid=Cj0KCQjw_abc-123&x=1', '/angebot/', ['art' => 'weg']);
        self::assertSame('/angebot/?utm_source=google&utm_medium=cpc&gclid=Cj0KCQjw_abc-123&lp=%2Fweg-verwaltung%2F&art=weg', $url);
    }

    public function testAddsExternalReferrerHostOnly(): void
    {
        $url = $this->url('/verwalterwechsel/', '/angebot/', ['anlass' => 'wechsel'], ['HTTP_REFERER' => 'https://www.google.de/search?q=test']);
        self::assertSame('/angebot/?lp=%2Fverwalterwechsel%2F&ref=www.google.de&anlass=wechsel', $url);
    }

    public function testIgnoresOwnReferrer(): void
    {
        $url = $this->url('/', '/angebot/', [], ['HTTP_REFERER' => 'https://www.muellerhv.de/wissen/']);
        self::assertSame('/angebot/?lp=%2F', $url);
    }

    public function testWithoutRequest(): void
    {
        self::assertSame('/angebot/?art=se', (new AttributionExtension(new RequestContext()))->ctaUrl('/angebot/', ['art' => 'se']));
    }
}
