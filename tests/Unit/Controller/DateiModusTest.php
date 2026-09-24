<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Controller;

use Hvm\Http\Kernel;
use Hvm\Http\Request;
use Hvm\Service\WebhookService;
use Hvm\Tests\Unit\DateiModus;
use Hvm\Tests\Unit\TestCase;
use PDO;

/**
 * Betrieb ohne Datenbank (STORAGE_MODE=datei, Entscheidung der Geschäftsführung vom 24.09.2026):
 * Formulare legen verschlüsselte Outbox-Dateien an, der Versand läuft inline nach der Antwort (Webhook signiert,
 * interne Mail, Eingangsbestätigung), danach ist storage/outbox leer. Admin 404, /health ohne Datenbank.
 */
final class DateiModusTest extends TestCase
{
    use DateiModus;

    protected function tearDown(): void
    {
        $this->entferneDateiBase();
        parent::tearDown();
    }

    /**
     * @param array<string, string|null> $env
     */
    private function app(array $env = [], string $appEnv = 'staging'): Kernel
    {
        return $this->dateiKernel($this->kernel($appEnv, array_merge(self::dateiEnv(), $env)));
    }

    public function testStorageModeDefaultsToDatei(): void
    {
        self::assertSame('datei', $this->kernel('staging', ['STORAGE_MODE' => null])->config()->get('app.storage_mode'));
        self::assertSame('datei', $this->kernel('staging', ['STORAGE_MODE' => 'unsinn'])->config()->get('app.storage_mode'));
        self::assertSame('datenbank', $this->kernel('staging', ['STORAGE_MODE' => 'datenbank'])->config()->get('app.storage_mode'));
    }

    public function testNoDatabaseConnectionIsEverBuilt(): void
    {
        $kernel = $this->app();
        $this->expectException(\LogicException::class);
        $kernel->container()->get(PDO::class);
    }

    public function testPagesWorkWithoutDatabase(): void
    {
        $kernel = $this->app();
        foreach (['/', '/angebot/', '/kontakt/', '/karriere/bewerbung/', '/weg-verwaltung/', '/datenschutz/', '/sitemap.xml'] as $pfad) {
            self::assertSame(200, $kernel->handle(Request::create('GET', $pfad))->status(), $pfad);
        }
    }

    public function testAdminRoutesReturn404(): void
    {
        $kernel = $this->app();
        foreach (['/admin/', '/admin/login/', '/admin/leads/', '/admin/leads/export.csv'] as $pfad) {
            self::assertSame(404, $kernel->handle(Request::create('GET', $pfad))->status(), $pfad);
        }
    }

    public function testHealthReportsDatabaseNotUsed(): void
    {
        $response = $this->app([], 'production')->handle(Request::create('GET', '/health'));
        self::assertSame(200, $response->status());
        self::assertSame(['status' => 'ok', 'datenbank' => 'nicht_verwendet'], json_decode($response->body(), true));
    }

    public function testAngebotCreatesEncryptedJobsAndInlineDeliveryEmptiesOutbox(): void
    {
        $kernel = $this->app();
        // Ohne Versand nach der Antwort bleiben die verschlüsselten Aufträge liegen
        $csrf = $this->csrfVon($kernel, '/angebot/');
        $post = self::angebotDaten() + ['_csrf' => $csrf, '_zeit' => $kernel->container()->get(\Hvm\Security\SpamGuard::class)->issueToken('angebot', time() - 10), 'website' => ''];
        $post[\Hvm\Security\SpamGuard::HONEYPOT_FIELD] = '';
        $response = $kernel->handle(Request::create('POST', '/angebot/', $post, ['REMOTE_ADDR' => '198.51.100.41']));
        self::assertSame(303, $response->status());
        self::assertSame('/angebot/danke/', $response->header('Location'));
        $dateien = $this->outboxDateien();
        self::assertCount(3, $dateien, 'Webhook, interne Mail, Eingangsbestätigung');
        foreach ($dateien as $datei) {
            self::assertSame('0600', substr(sprintf('%o', fileperms($datei)), -4));
            $raw = (string) file_get_contents($datei);
            self::assertStringNotContainsString('erika.beispiel@example.org', $raw);
            self::assertStringNotContainsString('Beispiel', $raw);
        }
        self::assertStringContainsString('WEG-Verwaltung', $kernel->handle(Request::create('GET', '/angebot/danke/'))->body());

        $kernel->terminate(Request::create('POST', '/angebot/'));
        self::assertSame([], $this->outboxDateien(), 'nach erfolgreichem Versand gelöscht');

        self::assertCount(1, $this->webhooks);
        $hook = $this->webhooks[0];
        self::assertTrue(WebhookService::verify($hook['body'], $hook['headers']['X-HVM-Timestamp'], $hook['headers']['X-HVM-Signature'], self::WEBHOOK_SECRET));
        self::assertSame('angebot.eingegangen', $hook['headers']['X-HVM-Event']);
        $body = json_decode($hook['body'], true);
        self::assertSame('angebot', $body['typ']);
        self::assertSame('hvm-website/anfrage-1', $body['schema']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $body['uuid']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['created_at']);
        self::assertSame($body['created_at'], $body['consent_at']);
        self::assertSame('angebot-2026-09-entwurf-1', $body['consent_text_version']);
        self::assertSame('weg', $body['data']['management_form']);
        self::assertSame(['residential' => 12, 'commercial' => 1, 'parking' => 4], $body['data']['units']);
        self::assertSame('2027-01', $body['data']['management_start']);
        self::assertTrue($body['data']['has_current_manager']);
        self::assertSame('erika.beispiel@example.org', $body['data']['contact']['email']);
        self::assertSame('beirat', $body['data']['role']);
        self::assertSame('newsletter', $body['attribution']['utm_source']);
        self::assertSame('/weg-verwaltung/', $body['attribution']['landing_page']);
        self::assertSame('Düsseldorf', $body['attribution']['region']);

        self::assertCount(2, $this->gesendeteMails);
        [$intern, $bestaetigung] = $this->gesendeteMails;
        self::assertSame('leads@example.org', $intern['to']);
        self::assertSame('Neue Verwaltungsanfrage WEG, 13 Einheiten, PLZ-Bereich 50xxx', $intern['subject']);
        self::assertStringNotContainsString('Beispiel', $intern['subject']);
        self::assertStringContainsString('erika.beispiel@example.org', $intern['text']);
        self::assertStringContainsString('Musterweg 1 50667 Köln', $intern['text']);
        self::assertStringNotContainsString('/admin/', $intern['text'] . $intern['html']);
        self::assertSame('erika.beispiel@example.org', $bestaetigung['to']);
    }

    public function testKontaktAndSpam(): void
    {
        $kernel = $this->app();
        self::assertSame(303, $this->sende($kernel, '/kontakt/', 'kontakt', self::kontaktDaten())->status());
        self::assertSame([], $this->outboxDateien());
        $body = json_decode($this->webhooks[0]['body'], true);
        self::assertSame('kontakt', $body['typ']);
        self::assertSame('vermietung', $body['data']['subject']);
        self::assertSame('Erika Beispiel', $body['data']['name']);
        self::assertSame('Neue Kontaktanfrage: Vermietung', $this->gesendeteMails[0]['subject']);
        self::assertStringContainsString('Fiktive Testnachricht', $this->gesendeteMails[0]['text']);

        // Honeypot gefüllt: Danke-Seite, aber keine Datei, kein Versand
        $this->webhooks = [];
        $spam = $this->sende($kernel, '/kontakt/', 'kontakt', self::kontaktDaten() + [\Hvm\Security\SpamGuard::HONEYPOT_FIELD => 'http://spam.example.org'], null, '198.51.100.42');
        self::assertSame(303, $spam->status());
        self::assertSame([], $this->outboxDateien());
        self::assertSame([], $this->webhooks);
    }

    public function testBewerbungMailsPdfAndWebhookOnlyMetadata(): void
    {
        $kernel = $this->app();
        [$daten, $datei] = $this->bewerbungDaten();
        self::assertSame(303, $this->sende($kernel, '/karriere/bewerbung/', 'bewerbung', $daten, $datei)->status());

        self::assertSame([], $this->outboxDateien());
        self::assertSame([], glob($this->dateiBase . '/storage/uploads/bewerbungen/*') ?: [], 'PDF nach Mailversand gelöscht');
        $body = json_decode($this->webhooks[0]['body'], true);
        self::assertSame('bewerbung', $body['typ']);
        self::assertSame(['mime' => 'application/pdf', 'size_bytes' => filesize($datei['tmp_name']), 'delivery' => 'mail'], $body['data']['file']);
        self::assertTrue($body['data']['has_message']);
        self::assertStringNotContainsString('Anschreiben', $this->webhooks[0]['body']);
        self::assertStringNotContainsString('PDF-1.4', $this->webhooks[0]['body']);
        self::assertArrayNotHasKey('attribution', $body);

        $intern = $this->gesendeteMails[0];
        self::assertSame('bewerbung@example.org', $intern['to']);
        self::assertSame('Neue Bewerbung: Sachbearbeitung WEG-Verwaltung', $intern['subject']);
        self::assertStringStartsWith('%PDF-1.4', $intern['attachments'][0]['content']);
        self::assertSame('application/pdf', $intern['attachments'][0]['mime']);
        self::assertSame('max.mustermann@example.org', $this->gesendeteMails[1]['to']);
        self::assertArrayNotHasKey('attachments', $this->gesendeteMails[1]);
    }

    public function testWebhookFailureKeepsJobForRetry(): void
    {
        $kernel = $this->app();
        $this->webhookStatus = 503;
        $this->sende($kernel, '/kontakt/', 'kontakt', self::kontaktDaten());
        self::assertCount(1, $this->outboxDateien(), 'Webhook-Auftrag wartet auf Wiederholung');
        self::assertCount(2, $this->gesendeteMails);
        $log = (string) @file_get_contents(self::basePath() . '/storage/logs/app.log');
        self::assertStringNotContainsString('erika.beispiel@example.org', $log);
    }

    public function testFileRateLimitApplies(): void
    {
        $kernel = $this->app();
        $csrf = $this->csrfVon($kernel, '/kontakt/');
        for ($i = 1; $i <= 10; $i++) {
            self::assertSame(422, $this->sende($kernel, '/kontakt/', 'kontakt', ['_csrf' => $csrf, 'anliegen' => 'vermietung'], null, '203.0.113.77')->status());
        }
        $blocked = $this->sende($kernel, '/kontakt/', 'kontakt', ['_csrf' => $csrf, 'anliegen' => 'vermietung'], null, '203.0.113.77');
        self::assertSame(429, $blocked->status());
        self::assertNotNull($blocked->header('Retry-After'));
        $namen = implode(' ', array_map('basename', glob($this->dateiBase . '/storage/ratelimit/*') ?: []));
        self::assertStringNotContainsString('203.0.113.77', $namen);
    }

    public function testEinrichtungShowsOnlyDiagnoseHashAndAbschluss(): void
    {
        $kernel = $this->app(['SETUP_TOKEN' => 'fiktiver-einrichtungs-token-0123456789abcdef']);
        $ip = ['REMOTE_ADDR' => '203.0.113.10'];
        $csrf = function () use ($kernel, $ip): string {
            preg_match('/name="_csrf" value="([^"]+)"/', $kernel->handle(Request::create('GET', '/_einrichtung/', [], $ip))->body(), $m);

            return $m[1];
        };
        $login = $kernel->handle(Request::create('POST', '/_einrichtung/', ['aktion' => 'anmelden', 'token' => 'fiktiver-einrichtungs-token-0123456789abcdef', '_csrf' => $csrf()], $ip));
        self::assertSame(303, $login->status());
        $html = $kernel->handle(Request::create('GET', '/_einrichtung/', [], $ip))->body();
        self::assertStringContainsString('1. Diagnose', $html);
        self::assertStringContainsString('2. Hash-Hilfe', $html);
        self::assertStringContainsString('3. Einrichtung abschließen', $html);
        self::assertStringContainsString('STORAGE_MODE=datei (keine Datenbank', $html);
        self::assertStringContainsString('storage/outbox beschreibbar', $html);
        self::assertStringContainsString('N8N_WEBHOOK_URL gesetzt (https)', $html);
        self::assertStringNotContainsString('Migrationen ausführen', $html);
        self::assertStringNotContainsString('Erster Admin', $html);
        self::assertStringNotContainsString('DB_HOST gesetzt', $html);

        $migration = $kernel->handle(Request::create('POST', '/_einrichtung/', ['aktion' => 'migrieren', '_csrf' => $csrf()], $ip));
        self::assertSame(404, $migration->status());
        $abschluss = $kernel->handle(Request::create('POST', '/_einrichtung/', ['aktion' => 'abschliessen', 'bestaetigung' => 'ja', '_csrf' => $csrf()], $ip));
        self::assertSame(200, $abschluss->status());
        self::assertStringNotContainsString('/admin/login/', $abschluss->body());
        self::assertFileExists($this->dateiBase . '/storage/setup.lock');
    }
}
