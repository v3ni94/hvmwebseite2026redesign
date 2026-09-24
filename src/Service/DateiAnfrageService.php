<?php

declare(strict_types=1);

namespace Hvm\Service;

use DateTimeImmutable;
use DateTimeZone;
use Hvm\Security\Crypto;
use Hvm\Security\SpamGuard;
use Hvm\Support\Config;
use Hvm\Support\Log;
use Hvm\Support\Uuid;
use Hvm\Validation\AngebotValidator;
use Hvm\Validation\KontaktValidator;
use Hvm\View\View;

/**
 * Formulare ohne Datenbank (STORAGE_MODE=datei, Entscheidung der Geschäftsführung vom 24.09.2026).
 *
 * Nach Validierung und Spamprüfung legt der Dienst je Anfrage drei Outbox-Aufträge als verschlüsselte Dateien an
 * (FileOutbox): Webhook an n8n (einzige dauerhafte Speicherung, Format docs/n8n-webhook.md), interne
 * Benachrichtigung an LEAD_NOTIFY_TO (bei Bewerbungen BEWERBUNG_NOTIFY_TO mit PDF-Anhang) und Eingangsbestätigung.
 * Die Webseite selbst speichert nichts dauerhaft. Spamverdacht wird verworfen (nur Log ohne personenbezogene Daten).
 *
 * Nutzt keine Datenbank und darf daher auch ohne DB_* aus dem Container geholt werden.
 */
final class DateiAnfrageService
{
    public const SCHEMA = 'hvm-website/anfrage-1';

    public function __construct(
        private readonly FileOutbox $outbox,
        private readonly View $view,
        private readonly Config $config,
        private readonly Log $log,
    ) {
    }

    /**
     * @param array<string, mixed>   $data        AngebotValidator::validate()['data']
     * @param array<string, ?string> $attribution source, utm_*, landing_page, referrer, region
     * @param array{status: string, grund: ?string}|null $spam
     * @return array{id: int, uuid: string, status: string, indication: ?float}
     */
    public function angebot(array $data, array $attribution, ?array $spam, DateTimeImmutable $now): array
    {
        $uuid = Uuid::v4();
        if ($this->isSpam($spam, 'Angebotsformular', $uuid)) {
            return ['id' => 0, 'uuid' => $uuid, 'status' => 'spam', 'indication' => null];
        }
        [$created, $iso] = $this->times($now);
        $row = array_merge($data, $this->attribution($attribution), [
            'uuid' => $uuid,
            'created_at' => $created,
            'status' => 'neu',
            'consent_text_version' => LeadService::CONSENT_TEXT_VERSION,
        ]);
        $vars = [
            'lead' => $row,
            'art_label' => AngebotValidator::ARTEN[(string) $row['management_form']] ?? '',
            'rolle_label' => AngebotValidator::ROLLEN[(string) ($row['contact_role'] ?? '')] ?? null,
            'anrede_label' => AngebotValidator::ANREDEN[(string) ($row['contact_salutation'] ?? '')] ?? null,
            'plz_bereich' => LeadService::zipArea((string) ($row['object_zip'] ?? '')),
            'admin_url' => null,
            'anrede_zeile' => LeadService::salutationLine($row),
        ];
        $body = $this->body('angebot', $uuid, $iso, LeadService::CONSENT_TEXT_VERSION, [
            'management_form' => $row['management_form'],
            'management_form_label' => $vars['art_label'],
            'object' => [
                'street' => $row['object_street'] ?? null,
                'zip' => $row['object_zip'] ?? null,
                'city' => $row['object_city'] ?? null,
                'year_of_construction' => $row['year_of_construction'] ?? null,
            ],
            'units' => [
                'residential' => (int) ($row['units_residential'] ?? 0),
                'commercial' => (int) ($row['units_commercial'] ?? 0),
                'parking' => (int) ($row['units_parking'] ?? 0),
            ],
            'management_start' => isset($row['management_start']) ? substr((string) $row['management_start'], 0, 7) : null,
            'has_current_manager' => $row['has_current_manager'] ?? null,
            'contact' => [
                'salutation' => $row['contact_salutation'] ?? null,
                'first_name' => $row['contact_first_name'] ?? null,
                'last_name' => $row['contact_last_name'] ?? null,
                'email' => $row['contact_email'] ?? null,
                'phone' => $row['contact_phone'] ?? null,
            ],
            'role' => $row['contact_role'] ?? null,
            'role_label' => $vars['rolle_label'],
            'message' => $row['message'] ?? null,
        ], $row);

        $this->enqueue('angebot', $uuid, $body, $this->mails(
            'lead',
            'emails/lead-benachrichtigung',
            LeadService::notifySubject($row),
            'emails/lead-bestaetigung',
            'Ihre Anfrage bei der Hausverwaltung Müller GmbH',
            (string) $row['contact_email'],
            $vars,
            $created,
        ));

        return ['id' => 0, 'uuid' => $uuid, 'status' => 'neu', 'indication' => null];
    }

    /**
     * @param array<string, mixed>   $data        KontaktValidator::validate()['data']
     * @param array<string, ?string> $attribution
     * @param array{status: string, grund: ?string}|null $spam
     * @return array{id: int, uuid: string, status: string}
     */
    public function kontakt(array $data, array $attribution, ?array $spam, DateTimeImmutable $now): array
    {
        $uuid = Uuid::v4();
        if ($this->isSpam($spam, 'Kontaktformular', $uuid)) {
            return ['id' => 0, 'uuid' => $uuid, 'status' => 'spam'];
        }
        [$created, $iso] = $this->times($now);
        $row = array_merge($data, $this->attribution($attribution), [
            'uuid' => $uuid,
            'created_at' => $created,
            'status' => 'neu',
            'consent_text_version' => ContactService::CONSENT_TEXT_VERSION,
        ]);
        $label = KontaktValidator::ANLIEGEN[(string) ($row['anliegen'] ?? '')] ?? 'Allgemeine Anfrage';
        $vars = [
            'anfrage' => $row,
            'anliegen_label' => $label,
            'anrede_zeile' => ContactService::salutationLine($row),
        ];
        $body = $this->body('kontakt', $uuid, $iso, ContactService::CONSENT_TEXT_VERSION, [
            'subject' => $row['anliegen'] ?? null,
            'subject_label' => $label,
            'name' => $row['name'] ?? null,
            'email' => $row['email'] ?? null,
            'phone' => $row['telefon'] ?? null,
            'message' => $row['nachricht'] ?? null,
        ], $row);

        $this->enqueue('kontakt', $uuid, $body, $this->mails(
            'lead',
            'emails/kontakt-benachrichtigung',
            ContactService::notifySubject($row),
            'emails/kontakt-bestaetigung',
            'Ihre Nachricht bei der Hausverwaltung Müller GmbH',
            (string) $row['email'],
            $vars,
            $created,
        ));

        return ['id' => 0, 'uuid' => $uuid, 'status' => 'neu'];
    }

    /**
     * @param array<string, mixed> $data  BewerbungValidator::validate()['data']
     * @param array{tmp_name: string, groesse: int, mime: string} $datei geprüfte Datei
     * @param array{status: string, grund: ?string}|null $spam
     * @return array{id: int, uuid: string, status: string}
     */
    public function bewerbung(array $data, array $datei, ?array $spam, DateTimeImmutable $now): array
    {
        $uuid = Uuid::v4();
        if ($this->isSpam($spam, 'Bewerbungsformular', $uuid)) {
            return ['id' => 0, 'uuid' => $uuid, 'status' => 'spam'];
        }
        [$created, $iso] = $this->times($now);

        [$relative, $absolute] = $this->outbox->attachmentPath($uuid);
        if (!is_dir(dirname($absolute)) && !@mkdir(dirname($absolute), 0700, true) && !is_dir(dirname($absolute))) {
            throw new \RuntimeException('Verzeichnis für Bewerbungsunterlagen konnte nicht angelegt werden.');
        }
        $plain = file_get_contents($datei['tmp_name']);
        if ($plain === false) {
            throw new \RuntimeException('Hochgeladene Datei konnte nicht gelesen werden.');
        }
        Crypto::encryptToFile($plain, $absolute, SpamGuard::deriveKey($this->config, 'bewerbung-upload'));

        $row = array_merge($data, [
            'uuid' => $uuid,
            'created_at' => $created,
            'status' => 'neu',
            'datei_mime' => $datei['mime'],
            'datei_groesse' => $datei['groesse'],
            'consent_text_version' => ApplicationService::CONSENT_TEXT_VERSION,
        ]);
        $vars = [
            'bewerbung' => $row,
            'anrede_zeile' => ApplicationService::salutationLine($row),
        ];
        $body = $this->body('bewerbung', $uuid, $iso, ApplicationService::CONSENT_TEXT_VERSION, [
            'position' => ($row['stelle'] ?? '') !== '' ? $row['stelle'] : null,
            'name' => $row['name'] ?? null,
            'email' => $row['email'] ?? null,
            'phone' => $row['telefon'] ?? null,
            'has_message' => trim((string) ($row['nachricht'] ?? '')) !== '',
            'file' => [
                'mime' => $datei['mime'],
                'size_bytes' => $datei['groesse'],
                'delivery' => 'mail',
            ],
        ], null);

        $mails = $this->mails(
            'bewerbung',
            'emails/bewerbung-benachrichtigung',
            ApplicationService::notifySubject($row),
            'emails/bewerbung-bestaetigung',
            'Ihre Bewerbung bei der Hausverwaltung Müller GmbH',
            (string) $row['email'],
            $vars,
            $created,
        );
        $mails[0]['anhang'] = ['pfad' => $relative, 'name' => 'bewerbung-' . substr($uuid, 0, 8) . '.pdf', 'mime' => 'application/pdf'];
        try {
            $this->enqueue('bewerbung', $uuid, $body, $mails);
        } catch (\Throwable $e) {
            @unlink($absolute);
            throw $e;
        }

        return ['id' => 0, 'uuid' => $uuid, 'status' => 'neu'];
    }

    /**
     * @param array{status: string, grund: ?string}|null $spam
     */
    private function isSpam(?array $spam, string $formular, string $uuid): bool
    {
        if ($spam === null || $spam['status'] !== 'spam') {
            return false;
        }
        $this->log->info($formular . ': Spamverdacht verworfen (Dateimodus, keine Speicherung)', ['anfrage' => $uuid, 'grund' => $spam['grund']]);

        return true;
    }

    /**
     * @return array{0: string, 1: string} UTC als Y-m-d H:i:s und ISO 8601 mit Z
     */
    private function times(DateTimeImmutable $now): array
    {
        $utc = $now->setTimezone(new DateTimeZone('UTC'));

        return [$utc->format('Y-m-d H:i:s'), $utc->format('Y-m-d\TH:i:s\Z')];
    }

    /**
     * @param array<string, ?string> $attribution
     * @return array<string, ?string>
     */
    private function attribution(array $attribution): array
    {
        $keys = ['source', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'landing_page', 'referrer', 'region'];

        return array_merge(array_fill_keys($keys, null), array_intersect_key($attribution, array_flip($keys)));
    }

    /**
     * JSON für n8n (docs/n8n-webhook.md). Wird einmal erzeugt und bei Wiederholungen unverändert gesendet.
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $row  Quelle der Attribution, null = ohne Attribution (Bewerbung)
     */
    private function body(string $typ, string $uuid, string $iso, string $consentVersion, array $data, ?array $row): string
    {
        $body = [
            'schema' => self::SCHEMA,
            'event' => $typ . '.eingegangen',
            'typ' => $typ,
            'uuid' => $uuid,
            'created_at' => $iso,
            'consent_text_version' => $consentVersion,
            'consent_at' => $iso,
            'data' => $data,
        ];
        if ($row !== null) {
            $body['attribution'] = [
                'source' => $row['source'] ?? null,
                'utm_source' => $row['utm_source'] ?? null,
                'utm_medium' => $row['utm_medium'] ?? null,
                'utm_campaign' => $row['utm_campaign'] ?? null,
                'utm_term' => $row['utm_term'] ?? null,
                'utm_content' => $row['utm_content'] ?? null,
                'landing_page' => $row['landing_page'] ?? null,
                'referrer' => $row['referrer'] ?? null,
                'region' => $row['region'] ?? null,
            ];
        }

        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Interne Benachrichtigung (Empfänger zur Versandzeit aus der Konfiguration) und Eingangsbestätigung.
     *
     * @param array<string, mixed> $vars
     * @return list<array<string, mixed>>
     */
    private function mails(string $empfaenger, string $notifyTemplate, string $notifySubject, string $confirmTemplate, string $confirmSubject, string $to, array $vars, string $created): array
    {
        $vars += [
            'eingang' => new DateTimeImmutable($created, new DateTimeZone('UTC')),
            'basis_url' => rtrim((string) $this->config->get('app.url', ''), '/'),
            'request' => ['path' => '/', 'query' => []],
            'datei_modus' => true,
        ];

        return [
            [
                'to' => null,
                'empfaenger' => $empfaenger,
                'subject' => $notifySubject,
                'html' => $this->view->render($notifyTemplate . '.html.twig', $vars + ['betreff' => $notifySubject]),
                'text' => $this->view->render($notifyTemplate . '.txt.twig', $vars + ['betreff' => $notifySubject]),
            ],
            [
                'to' => $to,
                'subject' => $confirmSubject,
                'html' => $this->view->render($confirmTemplate . '.html.twig', $vars + ['betreff' => $confirmSubject]),
                'text' => $this->view->render($confirmTemplate . '.txt.twig', $vars + ['betreff' => $confirmSubject]),
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $mails
     */
    private function enqueue(string $formular, string $uuid, string $body, array $mails): void
    {
        $jobs = [['typ' => 'webhook', 'formular' => $formular, 'anfrage' => $uuid, 'payload' => ['event' => $formular . '.eingegangen', 'body' => $body]]];
        foreach ($mails as $mail) {
            $jobs[] = ['typ' => 'mail', 'formular' => $formular, 'anfrage' => $uuid, 'payload' => $mail];
        }
        $this->outbox->enqueueAll($jobs);
        $this->log->info('Anfrage als Outbox-Aufträge abgelegt (Dateimodus)', ['formular' => $formular, 'anfrage' => $uuid, 'auftraege' => count($jobs)]);
    }
}
