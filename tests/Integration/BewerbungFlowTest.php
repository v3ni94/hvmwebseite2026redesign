<?php

declare(strict_types=1);

namespace Hvm\Tests\Integration;

use Hvm\Http\Kernel;
use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Security\Crypto;
use Hvm\Security\SpamGuard;
use Hvm\Service\ApplicationService;

/**
 * Bewerbungsstrecke gegen die Testdatenbank: Speichern, Verschlüsselung der Datei, Outbox, Spam,
 * Doppelversand, Rate Limit. Hochgeladene Testdateien liegen unter storage/uploads/bewerbungen und
 * werden nach jedem Test wieder gelöscht (fiktive Testdaten, keine personenbezogenen Daten).
 */
final class BewerbungFlowTest extends FormularIntegrationTestCase
{
    private const EMAIL = 'max.mustermann@example.org';

    /** @var list<string> */
    private array $tempFiles = [];

    /** @var list<string> */
    private array $uploadedPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        foreach ($this->uploadedPaths as $path) {
            @unlink(self::basePath() . '/' . $path);
        }
        $this->tempFiles = [];
        $this->uploadedPaths = [];
        parent::tearDown();
    }

    private function pdfFile(string $inhalt = 'Fiktiver Lebenslauf, nur zu Testzwecken.'): string
    {
        $path = sys_get_temp_dir() . '/hvm-bewerbung-integration-' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($path, "%PDF-1.4\n" . $inhalt);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @return array<string, string>
     */
    private function valid(): array
    {
        return [
            'stelle' => 'Sachbearbeitung WEG-Verwaltung',
            'name' => 'Max Mustermann',
            'email' => self::EMAIL,
            'telefon' => '0211 123456',
            'nachricht' => 'Fiktives Anschreiben, nur zu Testzwecken.',
            'einwilligung' => '1',
        ];
    }

    private function csrf(Kernel $kernel): string
    {
        $html = $kernel->handle(Request::create('GET', '/karriere/bewerbung/'))->body();
        self::assertSame(1, preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $m));

        return $m[1];
    }

    /**
     * @param array<string, string> $post
     */
    private function submit(Kernel $kernel, array $post, ?string $datei, ?string $token = null, string $ip = '198.51.100.30'): Response
    {
        $post['_csrf'] ??= $this->csrf($kernel);
        $post[SpamGuard::TOKEN_FIELD] = $token ?? $kernel->container()->get(SpamGuard::class)->issueToken('bewerbung', time() - 10);
        $post[SpamGuard::HONEYPOT_FIELD] ??= '';

        $files = [];
        if ($datei !== null) {
            $files['datei'] = ['name' => 'lebenslauf.pdf', 'type' => 'application/pdf', 'tmp_name' => $datei, 'error' => 0, 'size' => filesize($datei)];
        }

        $request = new Request('POST', '/karriere/bewerbung/', [], $post, [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/karriere/bewerbung/',
            'REMOTE_ADDR' => $ip,
            'HTTP_HOST' => 'localhost',
        ], [], $files);

        return $kernel->handle($request);
    }

    public function testValidSubmissionStoresMetadataEncryptsFileAndEnqueuesMails(): void
    {
        $kernel = $this->kernel();
        $original = $this->pdfFile();
        $response = $this->submit($kernel, $this->valid(), $original);
        self::assertSame(303, $response->status());
        self::assertSame('/karriere/bewerbung/danke/', $response->header('Location'));

        $row = $this->row('SELECT * FROM job_applications WHERE email = ?', [self::EMAIL]);
        self::assertNotSame([], $row);
        $this->uploadedPaths[] = (string) $row['datei_pfad'];
        self::assertSame('neu', $row['status']);
        self::assertSame('application/pdf', $row['datei_mime']);
        self::assertSame((int) filesize($original), (int) $row['datei_groesse']);
        self::assertSame(ApplicationService::CONSENT_TEXT_VERSION, $row['consent_text_version']);
        self::assertStringStartsWith('storage/uploads/bewerbungen/', (string) $row['datei_pfad']);
        self::assertStringEndsWith('.bin', (string) $row['datei_pfad']);

        $absolutePath = self::basePath() . '/' . $row['datei_pfad'];
        self::assertFileExists($absolutePath);
        $onDisk = (string) file_get_contents($absolutePath);
        self::assertStringNotContainsString('Fiktiver Lebenslauf', $onDisk, 'die Datei liegt nicht im Klartext auf der Platte');

        $mails = $this->db()->query("SELECT * FROM outbox WHERE typ = 'mail' ORDER BY id")->fetchAll();
        self::assertCount(2, $mails);
        foreach ($mails as $mail) {
            $payload = json_decode((string) $mail['payload'], true);
            self::assertIsArray($payload);
            self::assertStringNotContainsString(self::EMAIL, (string) $payload['subject']);
            self::assertArrayNotHasKey('anhang', $payload, 'keine Anhänge in der Warteschlange');
        }
        $internal = array_values(array_filter($mails, static fn (array $m): bool => json_decode((string) $m['payload'], true)['to'] === null));
        self::assertCount(1, $internal);
        $internalPayload = json_decode((string) $internal[0]['payload'], true);
        self::assertStringNotContainsString('Mustermann', (string) $internalPayload['html']);
        self::assertStringNotContainsString(self::EMAIL, (string) $internalPayload['html']);
    }

    public function testEncryptedFileCanBeDecryptedByApplicationService(): void
    {
        $kernel = $this->kernel();
        $original = $this->pdfFile('Ganz konkreter Testinhalt für den Rundlauf.');
        $originalBytes = (string) file_get_contents($original);
        $response = $this->submit($kernel, $this->valid(), $original);
        self::assertSame(303, $response->status());

        $row = $this->row('SELECT uuid, datei_pfad FROM job_applications WHERE email = ?', [self::EMAIL]);
        $this->uploadedPaths[] = (string) $row['datei_pfad'];

        /** @var ApplicationService $service */
        $service = $kernel->container()->get(ApplicationService::class);
        $stream = fopen('php://memory', 'w+b');
        self::assertNotFalse($stream);
        $service->decryptToStream((string) $row['datei_pfad'], $stream);
        rewind($stream);
        $decrypted = stream_get_contents($stream);
        fclose($stream);

        self::assertSame($originalBytes, $decrypted);
    }

    public function testWrongKeyCannotDecryptTheFile(): void
    {
        $kernel = $this->kernel();
        $original = $this->pdfFile();
        $this->submit($kernel, $this->valid(), $original);
        $row = $this->row('SELECT datei_pfad FROM job_applications WHERE email = ?', [self::EMAIL]);
        $this->uploadedPaths[] = (string) $row['datei_pfad'];

        $this->expectException(\RuntimeException::class);
        Crypto::decryptFile(self::basePath() . '/' . $row['datei_pfad'], random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function testHoneypotIsStoredAsSpamWithoutMailsAndKeepsFileEncrypted(): void
    {
        $kernel = $this->kernel();
        $response = $this->submit($kernel, $this->valid() + [SpamGuard::HONEYPOT_FIELD => 'https://spam.example.org'], $this->pdfFile());
        self::assertSame(303, $response->status());

        $row = $this->row('SELECT status, datei_pfad FROM job_applications WHERE email = ?', [self::EMAIL]);
        self::assertSame('spam', $row['status']);
        $this->uploadedPaths[] = (string) $row['datei_pfad'];
        self::assertSame(0, $this->countRows('outbox'));
    }

    public function testMissingFileIsRejectedWithoutStoring(): void
    {
        $kernel = $this->kernel();
        $response = $this->submit($kernel, $this->valid(), null);
        self::assertSame(422, $response->status());
        self::assertSame(0, $this->countRows('job_applications'));
    }

    public function testDoubleSubmissionOfSameTokenIsNotStoredTwice(): void
    {
        $kernel = $this->kernel();
        $token = $kernel->container()->get(SpamGuard::class)->issueToken('bewerbung', time() - 10);

        $first = $this->submit($kernel, $this->valid(), $this->pdfFile(), $token);
        self::assertSame(303, $first->status());
        $row = $this->row('SELECT datei_pfad FROM job_applications WHERE email = ?', [self::EMAIL]);
        $this->uploadedPaths[] = (string) $row['datei_pfad'];

        $second = $this->submit($kernel, $this->valid(), $this->pdfFile(), $token);
        self::assertSame(303, $second->status());

        self::assertSame(1, $this->countRows('job_applications', 'email = ?', [self::EMAIL]));
    }

    public function testRateLimitOnStoredApplicationsPerIp(): void
    {
        $kernel = $this->kernel();
        $ip = '198.51.100.31';
        $spam = $kernel->container()->get(SpamGuard::class);
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->submit($kernel, ['email' => "limit{$i}@example.org"] + $this->valid(), $this->pdfFile(), $spam->issueToken('bewerbung', time() - 10 - $i), $ip);
            self::assertSame(303, $response->status(), 'Versuch ' . $i);
            $row = $this->row('SELECT datei_pfad FROM job_applications WHERE email = ?', ["limit{$i}@example.org"]);
            $this->uploadedPaths[] = (string) $row['datei_pfad'];
        }

        $blocked = $this->submit($kernel, ['email' => 'limit-blocked@example.org'] + $this->valid(), $this->pdfFile(), $spam->issueToken('bewerbung', time() - 30), $ip);
        self::assertSame(429, $blocked->status());
        self::assertSame(5, $this->countRows('job_applications'));
    }
}
