<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Service;

use Hvm\Service\LeadService;
use Hvm\Service\MailService;
use Hvm\Support\Config;
use PHPUnit\Framework\TestCase;

final class MailServiceTest extends TestCase
{
    public function testMissingConfigurationIsReportedWithoutValues(): void
    {
        $service = new MailService(new Config(['app' => ['mail' => ['host' => '', 'from' => null]]]));
        self::assertSame('MAIL_HOST, MAIL_FROM nicht gesetzt', $service->missingConfiguration());
        self::assertFalse($service->isConfigured());
    }

    public function testTransportReceivesSanitizedMessage(): void
    {
        $sent = [];
        $service = new MailService(
            new Config(['app' => ['mail' => ['host' => 'smtp.example.org', 'from' => 'website@example.org', 'from_name' => 'HVM']]]),
            function (array $message) use (&$sent): void {
                $sent = $message;
            }
        );
        $service->send('kunde@example.org', "Betreff\r\nBcc: x@example.org", '<p>h</p>', 't');
        self::assertSame('Betreff Bcc: x@example.org', $sent['subject'], 'keine Zeilenumbrüche im Betreff');
        self::assertSame('website@example.org', $sent['from']);

        $this->expectException(\InvalidArgumentException::class);
        $service->send("kunde@example.org\r\nBcc: x@example.org", 's', 'h', 't');
    }

    public function testLeadNotifyAddress(): void
    {
        self::assertNull((new MailService(new Config(['app' => ['lead_notify_to' => 'kaputt']])))->leadNotifyAddress());
        self::assertSame('leads@example.org', (new MailService(new Config(['app' => ['lead_notify_to' => 'leads@example.org']])))->leadNotifyAddress());
    }

    public function testNotifySubjectContainsNoPersonalData(): void
    {
        $subject = LeadService::notifySubject(['management_form' => 'weg', 'units_residential' => 12, 'units_commercial' => 0, 'object_zip' => '40789']);
        self::assertSame('Neue Verwaltungsanfrage WEG, 12 Einheiten, PLZ-Bereich 40xxx', $subject);
        self::assertSame('Neue Verwaltungsanfrage SE-Verwaltung, 1 Einheit, PLZ-Bereich 80xxx', LeadService::notifySubject(['management_form' => 'se', 'units_residential' => 1, 'object_zip' => '80802']));
    }

    public function testSalutationLine(): void
    {
        self::assertSame('Sehr geehrte Frau Beispiel,', LeadService::salutationLine(['contact_salutation' => 'frau', 'contact_last_name' => 'Beispiel']));
        self::assertSame('Sehr geehrter Herr Beispiel,', LeadService::salutationLine(['contact_salutation' => 'herr', 'contact_last_name' => 'Beispiel']));
        self::assertSame('Guten Tag Erika Beispiel,', LeadService::salutationLine(['contact_salutation' => 'keine', 'contact_first_name' => 'Erika', 'contact_last_name' => 'Beispiel']));
        self::assertSame('Guten Tag Erika,', LeadService::salutationLine(['contact_salutation' => 'frau', 'contact_first_name' => 'Erika']));
    }
}
