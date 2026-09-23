<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Service;

use Hvm\Service\WebhookService;
use Hvm\Support\Config;
use PHPUnit\Framework\TestCase;

final class WebhookServiceTest extends TestCase
{
    private const SECRET = 'test-geheimnis';

    private function config(?string $url = 'https://n8n.example.org/webhook/lead', ?string $secret = self::SECRET): Config
    {
        return new Config(['app' => ['n8n' => ['webhook_url' => $url, 'webhook_secret' => $secret]]]);
    }

    public function testSignatureIsHmacOverTimestampAndBody(): void
    {
        $body = '{"event":"lead.created"}';
        $expected = hash_hmac('sha256', '1790000000.' . $body, self::SECRET);
        self::assertSame($expected, WebhookService::sign($body, 1790000000, self::SECRET));
        // Bekannter Vektor, damit n8n die Prüfung nachbauen kann
        self::assertSame(hash_hmac('sha256', '1.{}', 'geheim'), WebhookService::sign('{}', 1, 'geheim'));
    }

    public function testVerify(): void
    {
        $sig = WebhookService::sign('{}', 1000, self::SECRET);
        self::assertTrue(WebhookService::verify('{}', 1000, $sig, self::SECRET, 300, 1100));
        self::assertFalse(WebhookService::verify('{ }', 1000, $sig, self::SECRET, 300, 1100), 'veränderter Body');
        self::assertFalse(WebhookService::verify('{}', 1000, $sig, 'anderes', 300, 1100), 'anderes Geheimnis');
        self::assertFalse(WebhookService::verify('{}', 1000, $sig, self::SECRET, 300, 2000), 'zu alt');
    }

    public function testSendUsesSignedHeaders(): void
    {
        $captured = [];
        $service = new WebhookService($this->config(), function (string $url, array $headers, string $body, int $timeout) use (&$captured): int {
            $captured = compact('url', 'headers', 'body', 'timeout');

            return 204;
        });
        self::assertSame(204, $service->send('{"a":1}', 'lead.created', '42', 1790000000));
        self::assertSame('https://n8n.example.org/webhook/lead', $captured['url']);
        self::assertSame('1790000000', $captured['headers']['X-HVM-Timestamp']);
        self::assertSame(WebhookService::sign('{"a":1}', 1790000000, self::SECRET), $captured['headers']['X-HVM-Signature']);
        self::assertSame('42', $captured['headers']['X-HVM-Delivery']);
        self::assertSame('lead.created', $captured['headers']['X-HVM-Event']);
        self::assertStringStartsWith('application/json', $captured['headers']['Content-Type']);
        self::assertLessThanOrEqual(10, $captured['timeout']);
    }

    public function testNon2xxThrows(): void
    {
        $service = new WebhookService($this->config(), static fn (): int => 500);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');
        $service->send('{}');
    }

    public function testUnconfiguredNeverSendsUnsigned(): void
    {
        $called = false;
        $client = static function () use (&$called): int {
            $called = true;

            return 200;
        };
        self::assertNotNull((new WebhookService($this->config(null), $client))->missingConfiguration());
        self::assertNotNull((new WebhookService($this->config('https://n8n.example.org/x', ''), $client))->missingConfiguration());
        self::assertNotNull((new WebhookService($this->config('ftp://n8n.example.org/x'), $client))->missingConfiguration());
        try {
            (new WebhookService($this->config('https://n8n.example.org/x', null), $client))->send('{}');
            self::fail('Ausnahme erwartet');
        } catch (\RuntimeException) {
        }
        self::assertFalse($called);
        self::assertTrue((new WebhookService($this->config(), $client))->isConfigured());
    }
}
