<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Service;

use Hvm\Http\Middleware\Csrf;
use Hvm\Http\RequestContext;
use Hvm\Http\Session;
use Hvm\Repository\OutboxRepository;
use Hvm\Service\ApplicationService;
use Hvm\Support\Config;
use Hvm\Support\Log;
use Hvm\View\View;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Prüft ausschließlich die Upload-Prüfung (Typ, Größe, Dateiendung), ohne Datenbankzugriff.
 * Speichern und Verschlüsselung prüft tests/Integration/BewerbungFlowTest.
 */
final class ApplicationServiceTest extends TestCase
{
    private const BASE_PATH_KEY = 'base64:' . 'VhLpHcx74RHgaI76JFORYfu6FwjjTZjgiP016Sf5We8=';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    private function basePath(): string
    {
        return dirname(__DIR__, 3);
    }

    private function service(): ApplicationService
    {
        $config = Config::fromDirectory($this->basePath() . '/config');
        $config->set('app.base_path', $this->basePath());
        $config->set('app.key', self::BASE_PATH_KEY);
        $config->set('app.env', 'development');

        // Ein leeres, unverbundenes SQLite in-memory PDO genügt: validateUpload() greift nie auf die
        // Datenbank zu.
        $pdo = new PDO('sqlite::memory:');
        $session = new Session(['driver' => 'array']);
        $csrf = new Csrf($session, []);
        $context = new RequestContext();
        $view = new View($config, $context, $csrf, $this->basePath() . '/templates', sys_get_temp_dir() . '/hvm-test-twig-cache', $this->basePath() . '/public/assets/build/manifest.json');
        $log = new Log(sys_get_temp_dir(), 'hvm-application-service-test.log');

        return new ApplicationService($pdo, new OutboxRepository($pdo), $view, $config, $log);
    }

    private function pdfFile(int $bytes = 187): string
    {
        $path = sys_get_temp_dir() . '/hvm-upload-' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($path, "%PDF-1.4\n" . str_repeat('A', max(0, $bytes - 9)));
        $this->tempFiles[] = $path;

        return $path;
    }

    private function textFile(): string
    {
        $path = sys_get_temp_dir() . '/hvm-upload-' . bin2hex(random_bytes(8)) . '.txt';
        file_put_contents($path, 'Kein PDF.');
        $this->tempFiles[] = $path;

        return $path;
    }

    public function testValidPdfIsAccepted(): void
    {
        $path = $this->pdfFile();
        $result = $this->service()->validateUpload([
            'name' => 'lebenslauf.pdf',
            'type' => 'application/pdf',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
        ]);

        self::assertTrue($result['ok']);
        self::assertNull($result['fehler']);
        self::assertSame('application/pdf', $result['mime']);
    }

    public function testMissingFileIsRejected(): void
    {
        $result = $this->service()->validateUpload(null);

        self::assertFalse($result['ok']);
        self::assertNotNull($result['fehler']);
    }

    public function testUploadErrorIsRejectedWithSizeMessage(): void
    {
        $result = $this->service()->validateUpload([
            'name' => 'lebenslauf.pdf',
            'type' => 'application/pdf',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_INI_SIZE,
            'size' => 0,
        ]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('zu groß', (string) $result['fehler']);
    }

    public function testWrongExtensionIsRejected(): void
    {
        $path = $this->pdfFile();
        $result = $this->service()->validateUpload([
            'name' => 'lebenslauf.docx',
            'type' => 'application/pdf',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
        ]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('.pdf', (string) $result['fehler']);
    }

    public function testWrongContentIsRejectedEvenWithPdfExtension(): void
    {
        $path = $this->textFile();
        $renamed = substr($path, 0, -4) . '.pdf';
        rename($path, $renamed);
        $this->tempFiles[] = $renamed;

        $result = $this->service()->validateUpload([
            'name' => 'lebenslauf.pdf',
            'type' => 'application/pdf',
            'tmp_name' => $renamed,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($renamed),
        ]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('keine gültige PDF-Datei', (string) $result['fehler']);
    }

    public function testTooLargeFileIsRejected(): void
    {
        $path = $this->pdfFile(ApplicationService::MAX_FILE_BYTES + 1024);
        $result = $this->service()->validateUpload([
            'name' => 'lebenslauf.pdf',
            'type' => 'application/pdf',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
        ]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('zu groß', (string) $result['fehler']);
    }

    public function testEmptyFileIsRejected(): void
    {
        $path = $this->pdfFile(0);
        file_put_contents($path, '');
        $result = $this->service()->validateUpload([
            'name' => 'lebenslauf.pdf',
            'type' => 'application/pdf',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => 0,
        ]);

        self::assertFalse($result['ok']);
    }

    public function testNoFileSelectedIsRejected(): void
    {
        $result = $this->service()->validateUpload([
            'name' => '',
            'type' => '',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 0,
        ]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('PDF-Datei bei', (string) $result['fehler']);
    }
}
