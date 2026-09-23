<?php

declare(strict_types=1);

namespace Hvm\Service;

use DateTimeImmutable;
use DateTimeZone;
use Hvm\Repository\OutboxRepository;
use Hvm\Security\Crypto;
use Hvm\Security\SpamGuard;
use Hvm\Support\Config;
use Hvm\Support\Log;
use Hvm\Support\Uuid;
use Hvm\Validation\UploadValidator;
use Hvm\View\View;
use PDO;
use Throwable;

/**
 * Verarbeitung einer Bewerbung (MP 6.3, MP 10): Datei außerhalb des Webroots verschlüsselt ablegen,
 * nur Metadaten in job_applications. Benachrichtigung ohne Anhang und ohne personenbezogene Daten
 * im Betreff. Der Admin-Bereich liest die Datei ausschließlich über decryptToStream().
 */
final class ApplicationService
{
    /** Version des Einwilligungshinweises im Bewerbungsformular. Bei jeder Textänderung erhöhen. [Freigabe Datenschutztext] */
    public const CONSENT_TEXT_VERSION = 'bewerbung-2026-09-entwurf-1';

    public const MAX_FILE_BYTES = 10 * 1024 * 1024;
    public const ALLOWED_MIME = 'application/pdf';
    private const UPLOAD_SUBDIR = 'storage/uploads/bewerbungen';

    private const COLUMNS = [
        'uuid', 'stelle', 'name', 'email', 'telefon', 'nachricht',
        'datei_pfad', 'datei_mime', 'datei_groesse',
        'consent_text_version', 'consent_at', 'status', 'created_at', 'updated_at',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly OutboxRepository $outbox,
        private readonly View $view,
        private readonly Config $config,
        private readonly Log $log,
    ) {
    }

    /**
     * Prüft eine hochgeladene Datei ($_FILES-Eintrag): Fehlercode, Größe, tatsächlicher Inhaltstyp
     * (finfo, nicht der vom Client gesendete Content-Type) und Dateiendung. Reine Delegation an
     * Hvm\Validation\UploadValidator (ohne Datenbankzugriff), damit BewerbungController die Datei auch
     * prüfen kann, wenn dieser Dienst mangels Datenbankverbindung nicht aus dem Container geholt werden
     * konnte.
     *
     * @param array<string, mixed>|null $file
     * @return array{ok: bool, fehler: ?string, tmp_name: ?string, groesse: int, mime: ?string}
     */
    public function validateUpload(?array $file): array
    {
        return UploadValidator::validate($file, self::ALLOWED_MIME, 'pdf', self::MAX_FILE_BYTES);
    }

    /**
     * @param array<string, mixed> $data     bereinigte Formularwerte (BewerbungValidator::validate()['data'])
     * @param array{tmp_name: string, groesse: int, mime: string} $datei geprüfte Datei (validateUpload())
     * @param array{status: string, grund: ?string}|null $spam
     * @return array{id: int, uuid: string, status: string}
     */
    public function submit(array $data, array $datei, ?array $spam, DateTimeImmutable $now): array
    {
        $uuid = Uuid::v4();
        $isSpam = $spam !== null && $spam['status'] === 'spam';
        $status = $isSpam ? 'spam' : 'neu';
        $timestamp = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $relativePath = self::UPLOAD_SUBDIR . '/' . $uuid . '.bin';
        $absolutePath = $this->absolutePath($relativePath);
        $this->ensureDirectory(dirname($absolutePath));

        $plaintext = file_get_contents($datei['tmp_name']);
        if ($plaintext === false) {
            throw new \RuntimeException('Hochgeladene Datei konnte nicht gelesen werden.');
        }
        Crypto::encryptToFile($plaintext, $absolutePath, $this->key());

        $row = array_merge($data, [
            'uuid' => $uuid,
            'status' => $status,
            'datei_pfad' => $relativePath,
            'datei_mime' => $datei['mime'],
            'datei_groesse' => $datei['groesse'],
            'consent_text_version' => self::CONSENT_TEXT_VERSION,
            'consent_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $messages = $isSpam ? [] : $this->renderMails($row);

        try {
            $this->pdo->beginTransaction();
            $id = $this->insert($row);
            if (!$isSpam) {
                foreach ($messages as $message) {
                    $this->outbox->enqueue('mail', $message, null);
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // Verschlüsselte Datei ohne zugehörigen Datenbankeintrag nicht verwaist liegen lassen.
            @unlink($absolutePath);
            throw $e;
        }

        $this->log->info($isSpam ? 'Bewerbung als Spamverdacht gespeichert' : 'Bewerbung gespeichert', [
            'bewerbung' => $uuid,
            'status' => $status,
            'grund' => $spam['grund'] ?? null,
        ]);

        return ['id' => $id, 'uuid' => $uuid, 'status' => $status];
    }

    /**
     * Entschlüsselt die Bewerbungsunterlage und schreibt sie in einen Stream (Admin-Download).
     * Der Aufrufer (Admin-Controller) prüft die Anmeldung, bevor diese Methode aufgerufen wird.
     *
     * @param resource $stream
     */
    public function decryptToStream(string $dateiPfad, $stream): void
    {
        $plain = Crypto::decryptFile($this->absolutePath($dateiPfad), $this->key());
        fwrite($stream, $plain);
        sodium_memzero($plain);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM job_applications WHERE uuid = ?');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function key(): string
    {
        return SpamGuard::deriveKey($this->config, 'bewerbung-upload');
    }

    private function absolutePath(string $relative): string
    {
        return rtrim((string) $this->config->get('app.base_path', ''), '/') . '/' . ltrim($relative, '/');
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Verzeichnis für Bewerbungsunterlagen konnte nicht angelegt werden.');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert(array $row): int
    {
        $data = array_intersect_key($row, array_flip(self::COLUMNS));
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO job_applications (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $this->pdo->prepare($sql)->execute(array_values($data));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Betreff der internen Benachrichtigung ohne personenbezogene Daten.
     *
     * @param array<string, mixed> $row
     */
    public static function notifySubject(array $row): string
    {
        $stelle = trim((string) ($row['stelle'] ?? ''));

        return $stelle === '' ? 'Neue Bewerbung (Initiativbewerbung)' : 'Neue Bewerbung: ' . self::shortenStelle($stelle);
    }

    private static function shortenStelle(string $stelle): string
    {
        return mb_strlen($stelle) > 60 ? mb_substr($stelle, 0, 57) . '...' : $stelle;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array{kind: string, to: ?string, subject: string, html: string, text: string}>
     */
    private function renderMails(array $row): array
    {
        $vars = [
            'bewerbung' => $row,
            'eingang' => new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            'anrede_zeile' => self::salutationLine($row),
            'basis_url' => rtrim((string) $this->config->get('app.url', ''), '/'),
            'request' => ['path' => '/', 'query' => []],
        ];
        $notifySubject = self::notifySubject($row);
        $confirmSubject = 'Ihre Bewerbung bei der Hausverwaltung Müller GmbH';

        return [
            [
                'kind' => 'bewerbung_notify',
                'to' => null,
                'subject' => $notifySubject,
                'html' => $this->view->render('emails/bewerbung-benachrichtigung.html.twig', $vars + ['betreff' => $notifySubject]),
                'text' => $this->view->render('emails/bewerbung-benachrichtigung.txt.twig', $vars + ['betreff' => $notifySubject]),
            ],
            [
                'kind' => 'bewerbung_bestaetigung',
                'to' => (string) $row['email'],
                'subject' => $confirmSubject,
                'html' => $this->view->render('emails/bewerbung-bestaetigung.html.twig', $vars + ['betreff' => $confirmSubject]),
                'text' => $this->view->render('emails/bewerbung-bestaetigung.txt.twig', $vars + ['betreff' => $confirmSubject]),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function salutationLine(array $row): string
    {
        $name = trim((string) ($row['name'] ?? ''));

        return $name === '' ? 'Guten Tag,' : 'Guten Tag ' . $name . ',';
    }
}
