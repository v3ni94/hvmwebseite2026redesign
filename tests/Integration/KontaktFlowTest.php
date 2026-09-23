<?php

declare(strict_types=1);

namespace Hvm\Tests\Integration;

use Hvm\Http\Kernel;
use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Security\SpamGuard;
use Hvm\Service\ContactService;

/**
 * Kontaktstrecke gegen die Testdatenbank: Speichern, Outbox, Spam, Doppelversand, Rate Limit.
 */
final class KontaktFlowTest extends FormularIntegrationTestCase
{
    private const EMAIL = 'erika.beispiel@example.org';

    /**
     * @return array<string, string>
     */
    private function valid(): array
    {
        return [
            'anliegen' => 'vermietung',
            'name' => 'Erika Beispiel',
            'email' => self::EMAIL,
            'telefon' => '0211 123456',
            'nachricht' => 'Fiktive Testnachricht über das Kontaktformular.',
            'datenschutz' => '1',
            'utm_source' => 'newsletter',
            'utm_campaign' => 'herbst',
            'landing_page' => '/vermietung/',
            'referrer' => 'www.google.de/search',
            'region' => 'Düsseldorf',
        ];
    }

    private function csrf(Kernel $kernel): string
    {
        $html = $kernel->handle(Request::create('GET', '/kontakt/'))->body();
        self::assertSame(1, preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $m));

        return $m[1];
    }

    /**
     * @param array<string, string> $post
     */
    private function submit(Kernel $kernel, array $post, ?string $token = null, string $ip = '198.51.100.20'): Response
    {
        $post['_csrf'] ??= $this->csrf($kernel);
        $post[SpamGuard::TOKEN_FIELD] = $token ?? $kernel->container()->get(SpamGuard::class)->issueToken('kontakt', time() - 10);
        $post[SpamGuard::HONEYPOT_FIELD] ??= '';

        return $kernel->handle(Request::create('POST', '/kontakt/', $post, ['REMOTE_ADDR' => $ip]));
    }

    public function testValidSubmissionStoresRequestAndEnqueuesMails(): void
    {
        $kernel = $this->kernel();
        $response = $this->submit($kernel, $this->valid());
        self::assertSame(303, $response->status());
        self::assertSame('/kontakt/danke/', $response->header('Location'));

        $row = $this->row('SELECT * FROM contact_requests WHERE email = ?', [self::EMAIL]);
        self::assertNotSame([], $row);
        self::assertSame('vermietung', $row['anliegen']);
        self::assertSame('neu', $row['status']);
        self::assertSame('newsletter', $row['utm_source']);
        self::assertSame('Düsseldorf', $row['region']);
        self::assertNotNull($row['consent_at']);
        self::assertSame(ContactService::CONSENT_TEXT_VERSION, $row['consent_text_version']);

        $mails = $this->db()->query("SELECT * FROM outbox WHERE typ = 'mail' ORDER BY id")->fetchAll();
        self::assertCount(2, $mails);
        foreach ($mails as $mail) {
            self::assertSame('pending', $mail['status']);
            $payload = json_decode((string) $mail['payload'], true);
            self::assertIsArray($payload);
            self::assertStringNotContainsString(self::EMAIL, (string) $payload['subject'], 'Betreff ohne personenbezogene Daten');
        }
        $internal = array_values(array_filter($mails, static fn (array $m): bool => json_decode((string) $m['payload'], true)['to'] === null));
        self::assertCount(1, $internal);
        $internalPayload = json_decode((string) $internal[0]['payload'], true);
        self::assertStringNotContainsString('Erika', (string) $internalPayload['html'], 'interne Benachrichtigung ohne Namen');
        self::assertStringNotContainsString(self::EMAIL, (string) $internalPayload['html']);

        $confirm = array_values(array_filter($mails, static fn (array $m): bool => json_decode((string) $m['payload'], true)['to'] === self::EMAIL));
        self::assertCount(1, $confirm);
    }

    public function testHoneypotIsStoredAsSpamWithoutMails(): void
    {
        $kernel = $this->kernel();
        $response = $this->submit($kernel, $this->valid() + [SpamGuard::HONEYPOT_FIELD => 'https://spam.example.org']);
        self::assertSame(303, $response->status());

        $row = $this->row('SELECT * FROM contact_requests WHERE email = ?', [self::EMAIL]);
        self::assertSame('spam', $row['status']);
        self::assertSame(0, $this->countRows('outbox'));
    }

    public function testTooFastSubmissionIsStoredAsSpam(): void
    {
        $kernel = $this->kernel();
        $response = $this->submit($kernel, $this->valid(), $kernel->container()->get(SpamGuard::class)->issueToken('kontakt', time()));
        self::assertSame(303, $response->status());

        $row = $this->row('SELECT status FROM contact_requests WHERE email = ?', [self::EMAIL]);
        self::assertSame('spam', $row['status']);
    }

    public function testDoubleSubmissionOfSameTokenIsNotStoredTwice(): void
    {
        $kernel = $this->kernel();
        $html = $kernel->handle(Request::create('GET', '/kontakt/'))->body();
        preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $m);
        $csrf = $m[1];
        $token = $kernel->container()->get(SpamGuard::class)->issueToken('kontakt', time() - 10);

        $post = $this->valid();
        $post['_csrf'] = $csrf;
        $post[SpamGuard::TOKEN_FIELD] = $token;
        $post[SpamGuard::HONEYPOT_FIELD] = '';

        $first = $kernel->handle(Request::create('POST', '/kontakt/', $post));
        self::assertSame(303, $first->status());

        // Gleicher CSRF-Token und gleiche Sitzung (derselbe Kernel), Zeit-Token erneut gültig,
        // aber bereits verwendet: Doppelversand darf nicht zu einem zweiten Datensatz führen.
        $second = $kernel->handle(Request::create('POST', '/kontakt/', $post));
        self::assertSame(303, $second->status());

        self::assertSame(1, $this->countRows('contact_requests', 'email = ?', [self::EMAIL]));
    }

    public function testRateLimitOnStoredRequestsPerIp(): void
    {
        $kernel = $this->kernel();
        $ip = '198.51.100.21';
        $spam = $kernel->container()->get(SpamGuard::class);
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->submit($kernel, ['email' => "limit{$i}@example.org"] + $this->valid(), $spam->issueToken('kontakt', time() - 10 - $i), $ip);
            self::assertSame(303, $response->status(), 'Versuch ' . $i);
        }

        $blocked = $this->submit($kernel, ['email' => 'limit-blocked@example.org'] + $this->valid(), $spam->issueToken('kontakt', time() - 30), $ip);
        self::assertSame(429, $blocked->status());
        self::assertNotNull($blocked->header('Retry-After'));
        self::assertSame(5, $this->countRows('contact_requests'));
    }

    public function testMissingRequiredFieldsAreRejectedWithoutStoring(): void
    {
        $kernel = $this->kernel();
        $response = $this->submit($kernel, ['anliegen' => 'allgemein']);
        self::assertSame(422, $response->status());
        self::assertSame(0, $this->countRows('contact_requests'));
    }
}
