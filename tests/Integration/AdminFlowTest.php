<?php

declare(strict_types=1);

namespace Hvm\Tests\Integration;

use Hvm\Http\Kernel;
use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Security\Totp;

/**
 * Admin-Bereich über den Kernel: Anmeldung mit TOTP, Liste, Filter, Detail, Statuswechsel, Notiz,
 * Zuweisung, Export, Dashboard, Abmeldung, Header und IP-Beschränkung.
 */
final class AdminFlowTest extends AdminTestCase
{
    private const IP = '198.51.100.30';

    private function request(Kernel $kernel, string $method, string $uri, array $post = [], string $ip = self::IP): Response
    {
        return $kernel->handle(Request::create($method, $uri, $post, ['REMOTE_ADDR' => $ip]));
    }

    private function csrf(Response $response): string
    {
        self::assertSame(1, preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $response->body(), $m), 'CSRF-Feld fehlt');

        return $m[1];
    }

    /**
     * @return array{id: int, secret: string}
     */
    private function login(Kernel $kernel): array
    {
        $admin = $this->createAdmin($kernel);
        $page = $this->request($kernel, 'GET', '/admin/login/');
        self::assertSame(200, $page->status());
        $response = $this->request($kernel, 'POST', '/admin/login/', ['_csrf' => $this->csrf($page), 'email' => 'admin@example.org', 'passwort' => self::PASSWORD]);
        self::assertSame(303, $response->status());
        self::assertSame('/admin/login/code/', $response->header('Location'));

        $codePage = $this->request($kernel, 'GET', '/admin/login/code/');
        self::assertSame(200, $codePage->status());
        $response = $this->request($kernel, 'POST', '/admin/login/code/', ['_csrf' => $this->csrf($codePage), 'code' => Totp::code($admin['secret'])]);
        self::assertSame(303, $response->status());
        self::assertSame('/admin/', $response->header('Location'));

        return $admin;
    }

    private static function assertAdminHeaders(Response $response): void
    {
        self::assertSame('noindex, nofollow', $response->header('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
        self::assertStringNotContainsString('unsafe-inline', (string) $response->header('Content-Security-Policy'));
    }

    public function testProtectedPagesRedirectToLogin(): void
    {
        $kernel = $this->kernel();
        foreach (['/admin/', '/admin/leads/', '/admin/leads/export.csv'] as $path) {
            $response = $this->request($kernel, 'GET', $path);
            self::assertSame(303, $response->status(), $path);
            self::assertSame('/admin/login/', $response->header('Location'));
            self::assertAdminHeaders($response);
        }
        self::assertSame(303, $this->request($kernel, 'GET', '/admin/login/code/')->status());
    }

    public function testLoginFormHasNoInlineStylesOrHandlers(): void
    {
        $response = $this->request($this->kernel(), 'GET', '/admin/login/');
        self::assertAdminHeaders($response);
        self::assertStringNotContainsString('style="', $response->body());
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+="/', $response->body());
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $response->body());
    }

    public function testWrongPasswordShowsGenericMessage(): void
    {
        $kernel = $this->kernel();
        $this->createAdmin($kernel);
        $page = $this->request($kernel, 'GET', '/admin/login/');
        $response = $this->request($kernel, 'POST', '/admin/login/', ['_csrf' => $this->csrf($page), 'email' => 'admin@example.org', 'passwort' => 'falsch']);
        self::assertSame(422, $response->status());
        self::assertStringContainsString('E-Mail-Adresse oder Passwort ist nicht korrekt.', $response->body());
        $unknown = $this->request($kernel, 'POST', '/admin/login/', ['_csrf' => $this->csrf($page), 'email' => 'niemand@example.org', 'passwort' => 'falsch']);
        self::assertSame(422, $unknown->status());
        self::assertStringContainsString('E-Mail-Adresse oder Passwort ist nicht korrekt.', $unknown->body());
    }

    public function testLoginWithoutCsrfIsRejected(): void
    {
        $kernel = $this->kernel();
        $this->createAdmin($kernel);
        $this->request($kernel, 'GET', '/admin/login/');
        $response = $this->request($kernel, 'POST', '/admin/login/', ['email' => 'admin@example.org', 'passwort' => self::PASSWORD]);
        self::assertSame(403, $response->status());
        self::assertSame(0, $this->countRows('admin_login_attempts'));
    }

    public function testLockoutReturns429WithRetryAfter(): void
    {
        $kernel = $this->kernel();
        $this->createAdmin($kernel);
        $token = $this->csrf($this->request($kernel, 'GET', '/admin/login/'));
        for ($i = 0; $i < 5; $i++) {
            $this->request($kernel, 'POST', '/admin/login/', ['_csrf' => $token, 'email' => 'admin@example.org', 'passwort' => 'falsch']);
        }
        $response = $this->request($kernel, 'POST', '/admin/login/', ['_csrf' => $token, 'email' => 'admin@example.org', 'passwort' => self::PASSWORD]);
        self::assertSame(429, $response->status());
        self::assertNotNull($response->header('Retry-After'));
        self::assertStringContainsString('Zu viele Anmeldeversuche', $response->body());
    }

    public function testLoginWithRecoveryCodeShowsRemainingCodes(): void
    {
        $kernel = $this->kernel();
        $admin = $this->createAdmin($kernel);
        $codes = (new \Hvm\Security\RecoveryCodes($this->db(), $kernel->config()))->regenerate($admin['id']);
        $page = $this->request($kernel, 'GET', '/admin/login/');
        $this->request($kernel, 'POST', '/admin/login/', ['_csrf' => $this->csrf($page), 'email' => 'admin@example.org', 'passwort' => self::PASSWORD]);
        $codePage = $this->request($kernel, 'GET', '/admin/login/code/');
        self::assertStringContainsString('Wiederherstellungscode', $codePage->body());
        self::assertStringNotContainsString('pattern="[0-9]{6}"', $codePage->body(), 'Feld muss auch Wiederherstellungscodes annehmen');

        $response = $this->request($kernel, 'POST', '/admin/login/code/', ['_csrf' => $this->csrf($codePage), 'code' => $codes[0]]);
        self::assertSame(303, $response->status());
        self::assertSame('/admin/', $response->header('Location'));
        $dashboard = $this->request($kernel, 'GET', '/admin/');
        self::assertSame(200, $dashboard->status());
        self::assertStringContainsString('mit einem Wiederherstellungscode angemeldet. Verbleibende Codes: 9.', $dashboard->body());
    }

    public function testFullAdminWorkflow(): void
    {
        $kernel = $this->kernel();
        $admin = $this->login($kernel);
        $uuidA = '11111111-1111-4111-8111-111111111111';
        $this->insertLead(['uuid' => $uuidA, 'object_zip' => '41061', 'source' => 'google_ads', 'created_at' => '2026-09-01 08:00:00']);
        $this->insertLead(['uuid' => '22222222-2222-4222-8222-222222222222', 'management_form' => 'miet', 'contact_first_name' => 'Max', 'contact_last_name' => 'Muster', 'object_zip' => '50667', 'object_city' => 'Köln', 'source' => null, 'created_at' => '2026-08-01 08:00:00']);
        $this->insertLead(['uuid' => '33333333-3333-4333-8333-333333333333', 'status' => 'spam', 'contact_first_name' => 'Spam', 'contact_last_name' => 'Versuch', 'contact_email' => '=cmd@example.org']);

        // Dashboard
        $dashboard = $this->request($kernel, 'GET', '/admin/');
        self::assertSame(200, $dashboard->status());
        self::assertAdminHeaders($dashboard);
        self::assertStringContainsString('Leads je Woche', $dashboard->body());
        self::assertStringContainsString('<svg class="c-admin-saeulen__grafik"', $dashboard->body());
        self::assertStringContainsString('<table class="c-tabelle', $dashboard->body());
        self::assertStringNotContainsString('style="', $dashboard->body());
        self::assertSame(1, preg_match('/Leads je Status.*?Spam<\/th><td class="is-zahl">1</s', $dashboard->body()));

        // Liste: Spam standardmäßig ausgeblendet
        $liste = $this->request($kernel, 'GET', '/admin/leads/');
        self::assertSame(200, $liste->status());
        self::assertStringContainsString('2 Leads', $liste->body());
        self::assertStringNotContainsString('Versuch', $liste->body());
        self::assertStringContainsString('Erika Beispiel', $liste->body());

        // Filter
        self::assertStringContainsString('1 Lead.', $this->request($kernel, 'GET', '/admin/leads/?art=miet')->body());
        self::assertStringContainsString('1 Lead.', $this->request($kernel, 'GET', '/admin/leads/?region=41')->body());
        self::assertStringContainsString('1 Lead.', $this->request($kernel, 'GET', '/admin/leads/?region=K%C3%B6ln')->body());
        self::assertStringContainsString('1 Lead.', $this->request($kernel, 'GET', '/admin/leads/?quelle=google_ads')->body());
        self::assertStringContainsString('1 Lead.', $this->request($kernel, 'GET', '/admin/leads/?quelle=%28ohne%29')->body());
        self::assertStringContainsString('1 Lead.', $this->request($kernel, 'GET', '/admin/leads/?von=2026-08-15&bis=2026-09-30')->body());
        self::assertStringContainsString('3 Leads', $this->request($kernel, 'GET', '/admin/leads/?status=alle')->body());
        $sortiert = $this->request($kernel, 'GET', '/admin/leads/?sort=eingang&richtung=asc')->body();
        self::assertLessThan(strpos($sortiert, 'Erika Beispiel'), strpos($sortiert, 'Max Muster'));
        // Ungültige Filterwerte werden verworfen, SQL bleibt unberührt
        self::assertSame(200, $this->request($kernel, 'GET', "/admin/leads/?sort=id;DROP%20TABLE%20leads&status=x'or'1")->status());

        // Detail
        $detail = $this->request($kernel, 'GET', '/admin/leads/' . $uuidA . '/?zurueck=' . rawurlencode('/admin/leads/?art=weg'));
        self::assertSame(200, $detail->status());
        self::assertStringContainsString('erika.beispiel@example.org', $detail->body());
        self::assertStringContainsString('Google Ads', $detail->body());
        self::assertStringContainsString('href="/admin/leads/?art=weg"', $detail->body());
        $token = $this->csrf($detail);

        // Statuswechsel mit Ereignis und Benutzer
        $response = $this->request($kernel, 'POST', '/admin/leads/' . $uuidA . '/status/', ['_csrf' => $token, 'status' => 'kontaktiert', 'zurueck' => '/admin/leads/?art=weg']);
        self::assertSame(303, $response->status());
        self::assertSame('/admin/leads/' . $uuidA . '/?zurueck=' . rawurlencode('/admin/leads/?art=weg'), $response->header('Location'));
        $event = $this->row("SELECT e.* FROM lead_events e JOIN leads l ON l.id = e.lead_id WHERE l.uuid = ? AND e.typ = 'status'", [$uuidA]);
        self::assertSame(['neu', 'kontaktiert', $admin['id']], [$event['von_status'], $event['nach_status'], (int) $event['admin_user_id']]);
        self::assertSame('kontaktiert', $this->row('SELECT status FROM leads WHERE uuid = ?', [$uuidA])['status']);
        self::assertStringContainsString('Status geändert: Kontaktiert.', $this->request($kernel, 'GET', '/admin/leads/' . $uuidA . '/')->body());

        // Offener Weiterleitungs-Parameter wird ignoriert
        $response = $this->request($kernel, 'POST', '/admin/leads/' . $uuidA . '/status/', ['_csrf' => $token, 'status' => 'angebot', 'zurueck' => 'https://example.org/']);
        self::assertSame('/admin/leads/' . $uuidA . '/', $response->header('Location'));

        // Notiz (escaped) und Zuweisung
        $this->request($kernel, 'POST', '/admin/leads/' . $uuidA . '/notiz/', ['_csrf' => $token, 'notiz' => '<script>alert(1)</script> Rückruf']);
        $this->request($kernel, 'POST', '/admin/leads/' . $uuidA . '/zuweisung/', ['_csrf' => $token, 'assigned_to' => (string) $admin['id']]);
        $detail = $this->request($kernel, 'GET', '/admin/leads/' . $uuidA . '/')->body();
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Rückruf', $detail);
        self::assertStringNotContainsString('<script>alert(1)</script>', $detail);
        self::assertStringContainsString('Zugewiesen an admin@example.org', $detail);
        self::assertSame($admin['id'], (int) $this->row('SELECT assigned_to FROM leads WHERE uuid = ?', [$uuidA])['assigned_to']);

        // Mutation ohne CSRF
        self::assertSame(403, $this->request($kernel, 'POST', '/admin/leads/' . $uuidA . '/status/', ['status' => 'spam'])->status());
        // Unbekannter Lead
        self::assertSame(404, $this->request($kernel, 'GET', '/admin/leads/99999999-9999-4999-8999-999999999999/')->status());

        // Export der gefilterten Liste (einschließlich Spam), Formelschutz
        $export = $this->request($kernel, 'GET', '/admin/leads/export.csv?status=alle');
        self::assertSame(200, $export->status());
        self::assertAdminHeaders($export);
        self::assertSame('text/csv; charset=utf-8', $export->header('Content-Type'));
        self::assertStringStartsWith('attachment; filename="leads-', (string) $export->header('Content-Disposition'));
        self::assertStringStartsWith("\xEF\xBB\xBFUUID;Eingang;", $export->body());
        self::assertSame(4, substr_count($export->body(), "\r\n"));
        self::assertStringContainsString(";'=cmd@example.org;", $export->body());
        $gefiltert = $this->request($kernel, 'GET', '/admin/leads/export.csv?art=miet');
        self::assertSame(2, substr_count($gefiltert->body(), "\r\n"));

        // Abmeldung per POST mit CSRF
        $logout = $this->request($kernel, 'POST', '/admin/logout/', ['_csrf' => $this->csrf($this->request($kernel, 'GET', '/admin/'))]);
        self::assertSame(303, $logout->status());
        self::assertSame(303, $this->request($kernel, 'GET', '/admin/')->status());
        self::assertStringContainsString('Sie wurden abgemeldet.', $this->request($kernel, 'GET', '/admin/login/')->body());
        self::assertSame(405, $this->request($kernel, 'GET', '/admin/logout/')->status());
    }

    public function testIpAllowlistBlocksAdminButNotPublicPages(): void
    {
        $kernel = $this->kernel(['ADMIN_IP_ALLOWLIST' => '203.0.113.0/24']);
        self::assertSame(403, $this->request($kernel, 'GET', '/admin/login/', [], '198.51.100.9')->status());
        self::assertSame(403, $this->request($kernel, 'GET', '/admin/', [], '198.51.100.9')->status());
        self::assertSame(200, $this->request($kernel, 'GET', '/admin/login/', [], '203.0.113.9')->status());
    }
}
