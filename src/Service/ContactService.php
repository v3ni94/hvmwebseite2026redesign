<?php

declare(strict_types=1);

namespace Hvm\Service;

use DateTimeImmutable;
use DateTimeZone;
use Hvm\Repository\OutboxRepository;
use Hvm\Support\Config;
use Hvm\Support\Log;
use Hvm\Support\Uuid;
use Hvm\Validation\KontaktValidator;
use Hvm\View\View;
use PDO;
use Throwable;

/**
 * Verarbeitung einer allgemeinen Kontaktanfrage (MP 6.3, MP 6.4 sinngemäß).
 *
 * In einer Transaktion: contact_requests-Zeile und die Outbox-Einträge (interne Benachrichtigung,
 * Eingangsbestätigung). Versand erfolgt asynchron durch bin/worker.php. Spamverdacht wird mit
 * Status spam gespeichert, ohne Mail.
 */
final class ContactService
{
    /** Version des Datenschutzhinweises im Kontaktformular. Bei jeder Textänderung erhöhen. [Freigabe Datenschutztext] */
    public const CONSENT_TEXT_VERSION = 'kontakt-2026-09-entwurf-1';

    private const COLUMNS = [
        'uuid', 'anliegen', 'name', 'email', 'telefon', 'nachricht', 'region',
        'consent_text_version', 'consent_at', 'status',
        'source', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'landing_page', 'referrer',
        'created_at', 'updated_at',
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
     * @param array<string, mixed>   $data        bereinigte Formularwerte (KontaktValidator::validate()['data'])
     * @param array<string, ?string> $attribution source, utm_*, landing_page, referrer, region
     * @param array{status: string, grund: ?string}|null $spam Ergebnis von SpamGuard::check() bei Spamverdacht
     * @return array{id: int, uuid: string, status: string}
     */
    public function submit(array $data, array $attribution, ?array $spam, DateTimeImmutable $now): array
    {
        $uuid = Uuid::v4();
        $isSpam = $spam !== null && $spam['status'] === 'spam';
        $status = $isSpam ? 'spam' : 'neu';
        $timestamp = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $attributionDefaults = array_fill_keys(
            ['source', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'landing_page', 'referrer', 'region'],
            null
        );

        $row = array_merge($data, [
            'uuid' => $uuid,
            'status' => $status,
            'consent_text_version' => self::CONSENT_TEXT_VERSION,
            'consent_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ], $attributionDefaults, array_intersect_key($attribution, $attributionDefaults));

        $messages = $isSpam ? [] : $this->renderMails($row);

        $this->pdo->beginTransaction();
        try {
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
            throw $e;
        }

        $this->log->info($isSpam ? 'Kontaktanfrage als Spamverdacht gespeichert' : 'Kontaktanfrage gespeichert', [
            'anfrage' => $uuid,
            'status' => $status,
            'grund' => $spam['grund'] ?? null,
        ]);

        return ['id' => $id, 'uuid' => $uuid, 'status' => $status];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert(array $row): int
    {
        $data = array_intersect_key($row, array_flip(self::COLUMNS));
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO contact_requests (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $this->pdo->prepare($sql)->execute(array_values($data));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Betreff der internen Benachrichtigung ohne personenbezogene Daten, z. B.
     * "Neue Kontaktanfrage: Vermietung".
     *
     * @param array<string, mixed> $row
     */
    public static function notifySubject(array $row): string
    {
        $label = KontaktValidator::ANLIEGEN[(string) ($row['anliegen'] ?? '')] ?? 'Allgemeine Anfrage';

        return 'Neue Kontaktanfrage: ' . $label;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array{kind: string, to: ?string, subject: string, html: string, text: string}>
     */
    private function renderMails(array $row): array
    {
        $vars = [
            'anfrage' => $row,
            'anliegen_label' => KontaktValidator::ANLIEGEN[(string) $row['anliegen']] ?? 'Allgemeine Anfrage',
            'eingang' => new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            'anrede_zeile' => self::salutationLine($row),
            'basis_url' => rtrim((string) $this->config->get('app.url', ''), '/'),
            'request' => ['path' => '/', 'query' => []],
        ];
        $notifySubject = self::notifySubject($row);
        $confirmSubject = 'Ihre Nachricht bei der Hausverwaltung Müller GmbH';

        return [
            [
                'kind' => 'kontakt_notify',
                'to' => null,
                'subject' => $notifySubject,
                'html' => $this->view->render('emails/kontakt-benachrichtigung.html.twig', $vars + ['betreff' => $notifySubject]),
                'text' => $this->view->render('emails/kontakt-benachrichtigung.txt.twig', $vars + ['betreff' => $notifySubject]),
            ],
            [
                'kind' => 'kontakt_bestaetigung',
                'to' => (string) $row['email'],
                'subject' => $confirmSubject,
                'html' => $this->view->render('emails/kontakt-bestaetigung.html.twig', $vars + ['betreff' => $confirmSubject]),
                'text' => $this->view->render('emails/kontakt-bestaetigung.txt.twig', $vars + ['betreff' => $confirmSubject]),
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
