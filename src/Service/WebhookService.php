<?php

declare(strict_types=1);

namespace Hvm\Service;

use Hvm\Support\Config;

/**
 * Webhook an n8n (MP 6.4), signiert mit HMAC-SHA256.
 *
 * Header:
 *   X-HVM-Timestamp: Unixzeit in Sekunden
 *   X-HVM-Signature: hex(HMAC-SHA256(N8N_WEBHOOK_SECRET, timestamp . "." . body))
 *   X-HVM-Event:     z. B. lead.created
 *   X-HVM-Delivery:  Outbox-ID (gleich bei Wiederholungen, zur Duplikaterkennung in n8n)
 *
 * Prüfung in n8n: Signatur neu berechnen, mit hash_equals vergleichen, Zeitstempel höchstens 5 Minuten alt.
 * Ohne URL oder ohne Geheimnis wird nie gesendet (keine unsignierten Webhooks).
 * In APP_ENV=production nur https. Ausnahme mit N8N_WEBHOOK_ALLOW_HTTP_INTERNAL=true: http an Hosts in
 * privaten oder reservierten Netzen (IP-Literal) und an Docker-interne Hostnamen ohne Punkt (z. B. http://n8n:5678).
 * Sonst gilt der Webhook als nicht konfiguriert, der Outbox-Eintrag wartet mit einer Fehlermeldung ohne URL.
 *
 * Der HTTP-Client ist austauschbar (Tests): callable(string $url, array<string, string> $headers, string $body, int $timeout): int
 * liefert den HTTP-Status und wirft bei Netzwerkfehlern eine Ausnahme.
 */
final class WebhookService
{
    public const TIMEOUT = 5;
    public const CONNECT_TIMEOUT = 3;

    /** @var (callable(string, array<string, string>, string, int): int)|null */
    private $client;

    public function __construct(private readonly Config $config, ?callable $client = null)
    {
        $this->client = $client;
    }

    public function withClient(callable $client): self
    {
        return new self($this->config, $client);
    }

    public static function sign(string $body, int|string $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public static function verify(string $body, int|string $timestamp, string $signature, string $secret, int $tolerance = 300, ?int $now = null): bool
    {
        $now ??= time();
        if (!ctype_digit((string) $timestamp) || abs($now - (int) $timestamp) > $tolerance) {
            return false;
        }

        return hash_equals(self::sign($body, $timestamp, $secret), $signature);
    }

    public function missingConfiguration(): ?string
    {
        $missing = [];
        $url = trim((string) $this->config->get('app.n8n.webhook_url', ''));
        $insecure = null;
        if ($url === '' || !preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $missing[] = 'N8N_WEBHOOK_URL';
        } else {
            $insecure = $this->insecureTransport($url);
        }
        if (trim((string) $this->config->get('app.n8n.webhook_secret', '')) === '') {
            $missing[] = 'N8N_WEBHOOK_SECRET';
        }

        $messages = [];
        if ($missing !== []) {
            $messages[] = implode(', ', $missing) . ' nicht gesetzt oder ungültig';
        }
        if ($insecure !== null) {
            $messages[] = $insecure;
        }

        return $messages === [] ? null : implode('; ', $messages);
    }

    /**
     * Meldung, wenn die URL in Produktion unverschlüsselt wäre. Enthält bewusst weder URL noch Host.
     */
    private function insecureTransport(string $url): ?string
    {
        if ($this->config->get('app.env', 'production') !== 'production') {
            return null;
        }
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https') {
            return null;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($this->allowHttpInternal() && self::isInternalHost($host)) {
            return null;
        }

        return $this->allowHttpInternal()
            ? 'N8N_WEBHOOK_URL muss in Produktion https verwenden (http nur für interne Hosts ohne Punkt oder private IP-Adressen)'
            : 'N8N_WEBHOOK_URL muss in Produktion https verwenden (http für interne Hosts nur mit N8N_WEBHOOK_ALLOW_HTTP_INTERNAL=true)';
    }

    private function allowHttpInternal(): bool
    {
        return $this->config->get('app.n8n.allow_http_internal', false) === true;
    }

    /**
     * Interner Host: IP-Literal aus privatem oder reserviertem Bereich (10/8, 172.16/12, 192.168/16, 127/8,
     * fc00::/7, ::1 usw.) oder Hostname ohne Punkt (Docker-Dienstname).
     */
    public static function isInternalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '') {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return !str_contains($host, '.') && preg_match('/^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$/', $host) === 1;
    }

    public function isConfigured(): bool
    {
        return $this->missingConfiguration() === null;
    }

    /**
     * Sendet den JSON-Body. Erfolg bei HTTP 2xx, sonst Ausnahme ohne Inhalt der Antwort.
     */
    public function send(string $body, string $event = 'lead.created', ?string $deliveryId = null, ?int $timestamp = null): int
    {
        $missing = $this->missingConfiguration();
        if ($missing !== null) {
            throw new \RuntimeException('Webhook nicht konfiguriert: ' . $missing);
        }
        $timestamp ??= time();
        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'User-Agent' => 'hvm-website-webhook/1',
            'X-HVM-Timestamp' => (string) $timestamp,
            'X-HVM-Signature' => self::sign($body, $timestamp, (string) $this->config->get('app.n8n.webhook_secret')),
            'X-HVM-Event' => $event,
        ];
        if ($deliveryId !== null) {
            $headers['X-HVM-Delivery'] = $deliveryId;
        }
        $url = trim((string) $this->config->get('app.n8n.webhook_url'));
        $status = $this->client !== null
            ? ($this->client)($url, $headers, $body, self::TIMEOUT)
            : $this->post($url, $headers, $body);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('Webhook antwortet mit HTTP %d.', $status));
        }

        return $status;
    }

    /**
     * @param array<string, string> $headers
     */
    private function post(string $url, array $headers, string $body): int
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            $result = curl_exec($ch);
            if ($result === false) {
                $error = curl_errno($ch);
                curl_close($ch);
                throw new \RuntimeException(sprintf('Webhook nicht erreichbar (cURL-Fehler %d).', $error));
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            return $status;
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $lines),
            'content' => $body,
            'timeout' => self::TIMEOUT,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $result = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if ($result === false && $statusLine === '') {
            throw new \RuntimeException('Webhook nicht erreichbar.');
        }

        return preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $m) ? (int) $m[1] : 0;
    }
}
