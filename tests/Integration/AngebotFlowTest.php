<?php

declare(strict_types=1);

namespace Hvm\Tests\Integration;

use Hvm\Http\Kernel;
use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Security\SpamGuard;
use Hvm\Support\Clock;
use PDO;

/**
 * Angebotsstrecke gegen die Testdatenbank: Speichern mit Event und Outbox, Spam, Doppelversand, Rate Limit, Preisstaffeln.
 */
final class AngebotFlowTest extends IntegrationTestCase
{
    private const EMAIL = 'erika.beispiel@example.org';

    /**
     * @return array<string, string>
     */
    private function valid(): array
    {
        return [
            'art' => 'weg',
            'strasse' => 'Musterweg 1',
            'plz' => '50667',
            'ort' => 'Köln',
            'baujahr' => '1995',
            'wohneinheiten' => '12',
            'gewerbeeinheiten' => '1',
            'stellplaetze' => '4',
            'beginn' => '2027-01',
            'aktueller_verwalter' => 'ja',
            'anrede' => 'frau',
            'vorname' => 'Erika',
            'nachname' => 'Beispiel',
            'email' => self::EMAIL,
            'telefon' => '0211 123456',
            'rolle' => 'beirat',
            'nachricht' => 'Fiktive Testnachricht.',
            'datenschutz' => '1',
            'utm_source' => 'newsletter',
            'utm_campaign' => 'herbst',
            'landing_page' => '/weg-verwaltung/',
            'referrer' => 'www.google.de/search',
            'region' => 'Düsseldorf',
        ];
    }

    private function csrf(Kernel $kernel): string
    {
        $html = $kernel->handle(Request::create('GET', '/angebot/'))->body();
        self::assertSame(1, preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $m));

        return $m[1];
    }

    /**
     * @param array<string, string> $post
     */
    private function submit(Kernel $kernel, array $post, ?string $token = null, string $ip = '198.51.100.7'): Response
    {
        $post['_csrf'] ??= $this->csrf($kernel);
        $post[SpamGuard::TOKEN_FIELD] = $token ?? $kernel->container()->get(SpamGuard::class)->issueToken('angebot', time() - 10);
        $post[SpamGuard::HONEYPOT_FIELD] ??= '';

        return $kernel->handle(Request::create('POST', '/angebot/', $post, ['REMOTE_ADDR' => $ip]));
    }

    public function testValidSubmissionStoresLeadEventAndOutboxInOneGo(): void
    {
        $kernel = $this->kernel();
        $response = $this->submit($kernel, $this->valid());
        self::assertSame(303, $response->status());
        self::assertSame('/angebot/danke/', $response->header('Location'));

        $lead = $this->row('SELECT * FROM leads');
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $lead['uuid']);
        self::assertSame('neu', $lead['status']);
        self::assertSame('weg', $lead['management_form']);
        self::assertSame('50667', $lead['object_zip']);
        self::assertSame(12, (int) $lead['units_residential']);
        self::assertSame(1, (int) $lead['units_commercial']);
        self::assertSame(4, (int) $lead['units_parking']);
        self::assertSame('2027-01-01', $lead['management_start']);
        self::assertSame(1, (int) $lead['has_current_manager']);
        self::assertSame(self::EMAIL, $lead['contact_email']);
        self::assertSame('beirat', $lead['contact_role']);
        self::assertSame('newsletter', $lead['source']);
        self::assertSame('herbst', $lead['utm_campaign']);
        self::assertSame('/weg-verwaltung/', $lead['landing_page']);
        self::assertSame('www.google.de/search', $lead['referrer']);
        self::assertSame('Düsseldorf', $lead['region']);
        self::assertNotEmpty($lead['consent_text_version']);
        self::assertNotEmpty($lead['consent_at']);
        self::assertNull($lead['price_tier_residential_id'], 'ohne Staffeln keine Zuordnung');

        $event = $this->row('SELECT * FROM lead_events WHERE lead_id = ?', [$lead['id']]);
        self::assertSame('eingang', $event['typ']);
        self::assertSame('neu', $event['nach_status']);

        $outbox = $this->db()->query('SELECT typ, status, attempts, payload FROM outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(3, $outbox);
        self::assertSame(['webhook', 'mail', 'mail'], array_column($outbox, 'typ'));
        self::assertSame(['pending', 'pending', 'pending'], array_column($outbox, 'status'));

        $webhook = json_decode((string) $outbox[0]['payload'], true);
        $body = json_decode((string) $webhook['body'], true);
        self::assertSame($lead['uuid'], $body['lead']['uuid']);
        self::assertSame(self::EMAIL, $body['lead']['contact']['email']);
        self::assertSame('newsletter', $body['lead']['attribution']['source']);

        $notify = json_decode((string) $outbox[1]['payload'], true);
        self::assertNull($notify['to'], 'Empfänger kommt zur Laufzeit aus LEAD_NOTIFY_TO');
        self::assertSame('Neue Verwaltungsanfrage WEG, 13 Einheiten, PLZ-Bereich 50xxx', $notify['subject']);
        foreach (['Erika', 'Beispiel', self::EMAIL, 'Musterweg', '123456', '50667', 'Köln'] as $pii) {
            self::assertStringNotContainsString($pii, $notify['subject']);
            self::assertStringNotContainsString($pii, $notify['html'], 'Benachrichtigung enthält keine personenbezogenen Daten');
            self::assertStringNotContainsString($pii, $notify['text']);
        }
        self::assertStringContainsString('/admin/leads/' . $lead['uuid'] . '/', $notify['html']);
        self::assertStringContainsString('HRB 104762', $notify['html']);
        self::assertStringContainsString('Amtsgericht Düsseldorf', $notify['text']);

        $confirm = json_decode((string) $outbox[2]['payload'], true);
        self::assertSame(self::EMAIL, $confirm['to']);
        self::assertStringContainsString('Sehr geehrte Frau Beispiel,', $confirm['html']);
        self::assertStringContainsString('[Antwortzeit festlegen]', $confirm['text']);
        self::assertStringContainsString('Geschäftsführer: Timo Müller', $confirm['text']);
        self::assertDoesNotMatchRegularExpression('/[\x{2012}-\x{2015}\x{2212}]/u', $confirm['html'] . $confirm['text'] . $notify['html'] . $notify['text']);

        $danke = $kernel->handle(Request::create('GET', '/angebot/danke/'))->body();
        self::assertStringContainsString('Ihre Anfrage zur WEG-Verwaltung ist bei uns eingegangen.', $danke);
        self::assertStringNotContainsString(self::EMAIL, $danke);
        $zweiterAufruf = $kernel->handle(Request::create('GET', '/angebot/danke/'))->body();
        self::assertStringNotContainsString('WEG-Verwaltung ist bei uns', $zweiterAufruf, 'Abschlussinfo nur einmal');
    }

    public function testDoubleSubmissionOfSameFormIsStoredOnce(): void
    {
        $kernel = $this->kernel();
        $token = $kernel->container()->get(SpamGuard::class)->issueToken('angebot', time() - 10);
        $csrf = $this->csrf($kernel);
        self::assertSame(303, $this->submit($kernel, $this->valid() + ['_csrf' => $csrf], $token)->status());
        self::assertSame(303, $this->submit($kernel, $this->valid() + ['_csrf' => $csrf], $token)->status());
        self::assertSame(1, $this->countRows('leads'));
    }

    public function testSpamIsStoredWithStatusSpamWithoutMailOrWebhook(): void
    {
        $kernel = $this->kernel();
        $response = $this->submit($kernel, [SpamGuard::HONEYPOT_FIELD => 'https://spam.example.org'] + $this->valid());
        self::assertSame(303, $response->status());
        $lead = $this->row('SELECT id, status FROM leads');
        self::assertSame('spam', $lead['status']);
        self::assertSame('Spamverdacht: honeypot', $this->row('SELECT notiz FROM lead_events WHERE lead_id = ?', [$lead['id']])['notiz']);
        self::assertSame(0, $this->countRows('outbox'));
    }

    public function testRateLimitPerIpReturns429(): void
    {
        // Mitten im festen 10-Minuten-Fenster des Rate Limiters, sonst kann ein Fensterwechsel den Zähler zurücksetzen
        \Hvm\Support\Clock::freeze('2026-09-23 10:05:00');
        Clock::freeze('2026-09-23 10:01:00');
        $kernel = $this->kernel();
        $csrf = $this->csrf($kernel);
        for ($i = 1; $i <= 10; $i++) {
            self::assertSame(422, $this->submit($kernel, ['art' => 'weg', '_csrf' => $csrf])->status(), 'Versuch ' . $i);
        }
        $blocked = $this->submit($kernel, ['art' => 'weg', '_csrf' => $csrf]);
        self::assertSame(429, $blocked->status());
        self::assertNotNull($blocked->header('Retry-After'));
        self::assertStringContainsString('Zu viele Anfragen in kurzer Zeit', $blocked->body());

        // andere IP ist nicht betroffen
        self::assertSame(422, $this->submit($kernel, ['art' => 'weg', '_csrf' => $csrf], null, '203.0.113.9')->status());

        $row = $this->row("SELECT key_hash, hits FROM rate_limits WHERE bucket = 'angebot_versuch' ORDER BY hits DESC");
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $row['key_hash']);
        self::assertNotSame(hash('sha256', '198.51.100.7'), $row['key_hash'], 'IP nur als HMAC, nicht als einfacher Hash');
        self::assertSame(11, (int) $row['hits']);

        // neues Zeitfenster
        Clock::freeze('2026-09-23 10:11:00');
        self::assertSame(422, $this->submit($kernel, ['art' => 'weg', '_csrf' => $csrf])->status());
    }

    public function testStoredLeadsPerIpAreLimited(): void
    {
        Clock::freeze('2026-09-23 10:01:00');
        $kernel = $this->kernel();
        $csrf = $this->csrf($kernel);
        $spam = $kernel->container()->get(SpamGuard::class);
        for ($i = 1; $i <= 5; $i++) {
            self::assertSame(303, $this->submit($kernel, $this->valid() + ['_csrf' => $csrf], $spam->issueToken('angebot', time() - 10 - $i))->status());
        }
        $response = $this->submit($kernel, $this->valid() + ['_csrf' => $csrf], $spam->issueToken('angebot', time() - 30));
        self::assertSame(429, $response->status());
        self::assertSame(5, $this->countRows('leads'));
    }

    public function testPriceTiersAreAssignedWhenAvailable(): void
    {
        // Fiktive Staffeln nur für diesen Test, keine echten Preise
        $insert = $this->db()->prepare('INSERT INTO price_tiers (management_form, unit_type, units_from, units_to, price_net_per_unit_month, valid_from) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute(['weg', 'residential', 1, 10, '1.00', '2020-01-01']);
        $insert->execute(['weg', 'residential', 11, null, '2.00', '2020-01-01']);
        $residential = (int) $this->db()->lastInsertId();
        $insert->execute(['weg', 'commercial', 1, null, '3.00', '2020-01-01']);
        $commercial = (int) $this->db()->lastInsertId();
        $insert->execute(['weg', 'parking', 1, null, '0.50', '2020-01-01']);
        $parking = (int) $this->db()->lastInsertId();

        $kernel = $this->kernel(['PRICE_INDICATION_ENABLED' => 'true']);
        $form = $kernel->handle(Request::create('GET', '/angebot/'))->body();
        self::assertStringContainsString('data-preise="', $form);
        self::assertStringContainsString('data-indikation hidden', $form);

        self::assertSame(303, $this->submit($kernel, $this->valid())->status());
        $lead = $this->row('SELECT price_tier_residential_id, price_tier_commercial_id, price_tier_parking_id FROM leads');
        self::assertSame($residential, (int) $lead['price_tier_residential_id']);
        self::assertSame($commercial, (int) $lead['price_tier_commercial_id']);
        self::assertSame($parking, (int) $lead['price_tier_parking_id']);

        // 12 x 2,00 + 1 x 3,00 + 4 x 0,50 = 29,00
        $danke = $kernel->handle(Request::create('GET', '/angebot/danke/'))->body();
        self::assertStringContainsString('29,00 EUR', $danke);
        self::assertStringContainsString('unverbindlich', $danke);
    }

    public function testNoPriceDisplayWhenDisabled(): void
    {
        $this->db()->exec("INSERT INTO price_tiers (management_form, unit_type, units_from, units_to, price_net_per_unit_month, valid_from) VALUES ('weg', 'residential', 1, NULL, 1.00, '2020-01-01')");
        $form = $this->kernel()->handle(Request::create('GET', '/angebot/'))->body();
        self::assertStringNotContainsString('data-preise', $form);
    }
}
