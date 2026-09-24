<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit;

use Hvm\Http\Kernel;
use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Security\SpamGuard;
use Hvm\Service\FileOutbox;
use Hvm\Service\InlineOutbox;
use Hvm\Service\MailService;
use Hvm\Service\WebhookService;
use Hvm\Support\Config;
use Hvm\Support\Log;

/**
 * Gemeinsame Hilfen für Tests im Dateimodus (STORAGE_MODE=datei): Kernel mit temporärem Speicherordner,
 * Fake-SMTP und Fake-Webhook-Empfänger. Alle Testdaten fiktiv (example.org).
 */
trait DateiModus
{
    protected const WEBHOOK_SECRET = 'fiktives-webhook-geheimnis-nur-fuer-tests-0123456789';

    protected string $dateiBase = '';

    /** @var list<array<string, mixed>> */
    protected array $gesendeteMails = [];

    /** @var list<array{url: string, headers: array<string, string>, body: string}> */
    protected array $webhooks = [];

    protected int $webhookStatus = 200;

    protected function dateiKernel(Kernel $kernel): Kernel
    {
        $this->dateiBase = sys_get_temp_dir() . '/hvm-dateimodus-' . bin2hex(random_bytes(6));
        mkdir($this->dateiBase . '/storage/logs', 0775, true);
        $base = $this->dateiBase;
        $c = $kernel->container();
        // Speicherorte (Rate Limit, Einrichtung) in den temporären Ordner, Templates bleiben im Projekt
        $kernel->config()->set('app.ratelimit_path', $base . '/storage/ratelimit');
        $kernel->config()->set('app.setup_storage', $base . '/storage');
        $c->set(MailService::class, fn ($c): MailService => new MailService($c->get(Config::class), function (array $message): void {
            $this->gesendeteMails[] = $message;
        }));
        $c->set(WebhookService::class, fn ($c): WebhookService => new WebhookService($c->get(Config::class), function (string $url, array $headers, string $body): int {
            $this->webhooks[] = ['url' => $url, 'headers' => $headers, 'body' => $body];

            return $this->webhookStatus;
        }));
        $c->set(FileOutbox::class, static fn ($c): FileOutbox => new FileOutbox(
            $base . '/storage/outbox',
            SpamGuard::deriveKey($c->get(Config::class), 'outbox-datei'),
            $c->get(MailService::class),
            $c->get(WebhookService::class),
            $c->get(Log::class),
            $base,
            SpamGuard::deriveKey($c->get(Config::class), 'bewerbung-upload'),
            null
        ));
        $c->set(InlineOutbox::class, static fn ($c): InlineOutbox => new InlineOutbox($c, $c->get(Config::class), $c->get(Log::class), $base . '/storage/cache'));

        return $kernel;
    }

    /**
     * @return array<string, string|null>
     */
    protected static function dateiEnv(): array
    {
        return [
            'STORAGE_MODE' => 'datei',
            'OUTBOX_MODE' => 'inline',
            // Absichtlich gesetzt: im Dateimodus darf trotzdem keine Verbindung entstehen
            'DB_HOST' => '192.0.2.1',
            'DB_NAME' => 'hvm_fiktiv',
            'MAIL_HOST' => 'smtp.example.org',
            'MAIL_FROM' => 'website@example.org',
            'LEAD_NOTIFY_TO' => 'leads@example.org',
            'BEWERBUNG_NOTIFY_TO' => 'bewerbung@example.org',
            'N8N_WEBHOOK_URL' => 'https://n8n.example.org/webhook/anfragen',
            'N8N_WEBHOOK_SECRET' => self::WEBHOOK_SECRET,
        ];
    }

    protected function entferneDateiBase(): void
    {
        if ($this->dateiBase !== '') {
            self::loescheOrdner($this->dateiBase);
        }
    }

    private static function loescheOrdner(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $dir . '/' . $entry;
                is_dir($path) && !is_link($path) ? self::loescheOrdner($path) : unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * @return list<string>
     */
    protected function outboxDateien(string $unterordner = ''): array
    {
        return glob($this->dateiBase . '/storage/outbox/' . ($unterordner === '' ? '' : $unterordner . '/') . '*' . FileOutbox::SUFFIX) ?: [];
    }

    protected function csrfVon(Kernel $kernel, string $pfad): string
    {
        $html = $kernel->handle(Request::create('GET', $pfad))->body();
        self::assertSame(1, preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $m), 'CSRF-Feld fehlt auf ' . $pfad);

        return $m[1];
    }

    /**
     * @param array<string, string>     $post
     * @param array<string, mixed>|null $datei
     */
    protected function sende(Kernel $kernel, string $pfad, string $formular, array $post, ?array $datei = null, string $ip = '198.51.100.40'): Response
    {
        $post['_csrf'] ??= $this->csrfVon($kernel, $pfad);
        $post[SpamGuard::TOKEN_FIELD] ??= $kernel->container()->get(SpamGuard::class)->issueToken($formular, time() - 10);
        $post[SpamGuard::HONEYPOT_FIELD] ??= '';
        $request = new Request('POST', $pfad, [], $post, [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => $pfad,
            'REMOTE_ADDR' => $ip,
            'HTTP_HOST' => 'localhost',
        ], [], $datei === null ? [] : ['datei' => $datei]);
        $response = $kernel->handle($request);
        $kernel->terminate($request);

        return $response;
    }

    /**
     * @return array<string, string>
     */
    protected static function angebotDaten(): array
    {
        return [
            'art' => 'weg', 'strasse' => 'Musterweg 1', 'plz' => '50667', 'ort' => 'Köln', 'baujahr' => '1995',
            'wohneinheiten' => '12', 'gewerbeeinheiten' => '1', 'stellplaetze' => '4', 'beginn' => '2027-01',
            'aktueller_verwalter' => 'ja', 'anrede' => 'frau', 'vorname' => 'Erika', 'nachname' => 'Beispiel',
            'email' => 'erika.beispiel@example.org', 'telefon' => '0211 123456', 'rolle' => 'beirat',
            'nachricht' => 'Fiktive Testnachricht.', 'datenschutz' => '1', 'utm_source' => 'newsletter',
            'utm_campaign' => 'herbst', 'landing_page' => '/weg-verwaltung/', 'referrer' => 'www.google.de/search',
            'region' => 'Düsseldorf',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function kontaktDaten(): array
    {
        return [
            'anliegen' => 'vermietung', 'name' => 'Erika Beispiel', 'email' => 'erika.beispiel@example.org',
            'telefon' => '0211 123456', 'nachricht' => 'Fiktive Testnachricht über das Kontaktformular.', 'datenschutz' => '1',
        ];
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, mixed>}
     */
    protected function bewerbungDaten(): array
    {
        $pfad = $this->dateiBase . '/lebenslauf.pdf';
        file_put_contents($pfad, "%PDF-1.4\nFiktiver Lebenslauf, nur zu Testzwecken.");

        return [
            [
                'stelle' => 'Sachbearbeitung WEG-Verwaltung', 'name' => 'Max Mustermann', 'email' => 'max.mustermann@example.org',
                'telefon' => '0211 123456', 'nachricht' => 'Fiktives Anschreiben, nur zu Testzwecken.', 'einwilligung' => '1',
            ],
            ['name' => 'lebenslauf.pdf', 'type' => 'application/pdf', 'tmp_name' => $pfad, 'error' => 0, 'size' => filesize($pfad)],
        ];
    }
}
