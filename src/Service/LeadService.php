<?php

declare(strict_types=1);

namespace Hvm\Service;

use DateTimeImmutable;
use DateTimeZone;
use Hvm\Repository\LeadRepository;
use Hvm\Repository\OutboxRepository;
use Hvm\Support\Config;
use Hvm\Support\Log;
use Hvm\Support\Uuid;
use Hvm\Validation\AngebotValidator;
use Hvm\View\View;
use Throwable;

/**
 * Verarbeitung einer Verwaltungsanfrage (MP 6.4).
 *
 * In einer Transaktion: Lead, lead_event "eingang" und die Outbox-Einträge (Webhook, interne
 * Benachrichtigung, Eingangsbestätigung). Versand erfolgt asynchron durch bin/worker.php.
 * Spamverdacht wird mit Status spam gespeichert, ohne Mail und Webhook.
 */
final class LeadService
{
    /** Version des Datenschutzhinweises im Formular. Bei jeder Textänderung erhöhen. [Freigabe Datenschutztext] */
    public const CONSENT_TEXT_VERSION = 'angebot-2026-09-entwurf-1';

    public const ART_KURZ = ['weg' => 'WEG', 'miet' => 'Mietverwaltung', 'se' => 'SE-Verwaltung'];

    public function __construct(
        private readonly LeadRepository $leads,
        private readonly OutboxRepository $outbox,
        private readonly PriceIndication $prices,
        private readonly View $view,
        private readonly Config $config,
        private readonly Log $log,
    ) {
    }

    /**
     * @param array<string, mixed>             $data        bereinigte Formularwerte (AngebotValidator::validate()['data'])
     * @param array<string, ?string>           $attribution source, utm_*, landing_page, referrer, region
     * @param array{status: string, grund: ?string}|null $spam Ergebnis von SpamGuard::check() bei Spamverdacht
     * @return array{id: int, uuid: string, status: string, indication: ?float}
     */
    public function submit(array $data, array $attribution, ?array $spam, DateTimeImmutable $now): array
    {
        $uuid = Uuid::v4();
        $isSpam = $spam !== null && $spam['status'] === 'spam';
        $status = $isSpam ? 'spam' : 'neu';
        $timestamp = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $tiers = ['residential' => null, 'commercial' => null, 'parking' => null, 'monthly_net' => null];
        try {
            if ($this->prices->tiers() !== []) {
                $tiers = $this->prices->assign(
                    (string) $data['management_form'],
                    (int) $data['units_residential'],
                    (int) $data['units_commercial'],
                    (int) $data['units_parking'],
                    $now
                );
            }
        } catch (Throwable $e) {
            $this->log->warning('Preisindikation nicht verfügbar', ['lead' => $uuid, 'fehler' => get_class($e)]);
        }

        $row = array_merge($data, [
            'uuid' => $uuid,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
            'status' => $status,
            'consent_text_version' => self::CONSENT_TEXT_VERSION,
            'consent_at' => $timestamp,
            'price_tier_residential_id' => $tiers['residential'],
            'price_tier_commercial_id' => $tiers['commercial'],
            'price_tier_parking_id' => $tiers['parking'],
        ], array_intersect_key($attribution, array_flip([
            'source', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'landing_page', 'referrer', 'region',
        ])));

        // Mails vor der Transaktion rendern: Fehler im Template dürfen keine halbe Transaktion hinterlassen.
        $messages = $isSpam ? [] : $this->renderMails($row);
        $webhookBody = $isSpam ? null : $this->webhookBody($row, $tiers);

        $pdo = $this->leads->pdo();
        $pdo->beginTransaction();
        try {
            $id = $this->leads->insert($row);
            $this->leads->addEvent($id, 'eingang', null, $status, null, $isSpam ? 'Spamverdacht: ' . (string) $spam['grund'] : null);
            if (!$isSpam) {
                $this->outbox->enqueue('webhook', ['event' => 'lead.created', 'lead_uuid' => $uuid, 'body' => $webhookBody], $id);
                foreach ($messages as $message) {
                    $this->outbox->enqueue('mail', $message + ['lead_uuid' => $uuid], $id);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->log->info($isSpam ? 'Lead als Spamverdacht gespeichert' : 'Lead gespeichert', [
            'lead' => $uuid,
            'status' => $status,
            'grund' => $spam['grund'] ?? null,
        ]);

        return [
            'id' => $id,
            'uuid' => $uuid,
            'status' => $status,
            'indication' => $this->prices->isEnabled() ? $tiers['monthly_net'] : null,
        ];
    }

    /**
     * Betreff der internen Benachrichtigung ohne personenbezogene Daten, z. B.
     * "Neue Verwaltungsanfrage WEG, 12 Einheiten, PLZ-Bereich 40xxx".
     *
     * @param array<string, mixed> $row
     */
    public static function notifySubject(array $row): string
    {
        $units = (int) ($row['units_residential'] ?? 0) + (int) ($row['units_commercial'] ?? 0);

        return sprintf(
            'Neue Verwaltungsanfrage %s, %d %s, PLZ-Bereich %s',
            self::ART_KURZ[(string) ($row['management_form'] ?? '')] ?? 'Verwaltung',
            $units,
            $units === 1 ? 'Einheit' : 'Einheiten',
            self::zipArea((string) ($row['object_zip'] ?? ''))
        );
    }

    public static function zipArea(string $zip): string
    {
        return preg_match('/^\d{5}$/', $zip) ? substr($zip, 0, 2) . 'xxx' : 'unbekannt';
    }

    public function adminUrl(string $uuid): string
    {
        return rtrim((string) $this->config->get('app.url', ''), '/') . '/admin/leads/' . $uuid . '/';
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array{kind: string, to: ?string, subject: string, html: string, text: string}>
     */
    private function renderMails(array $row): array
    {
        $vars = [
            'lead' => $row,
            'art_label' => AngebotValidator::ARTEN[(string) $row['management_form']] ?? '',
            'rolle_label' => AngebotValidator::ROLLEN[(string) ($row['contact_role'] ?? '')] ?? null,
            'plz_bereich' => self::zipArea((string) $row['object_zip']),
            'admin_url' => $this->adminUrl((string) $row['uuid']),
            'eingang' => new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            'anrede_zeile' => self::salutationLine($row),
            'basis_url' => rtrim((string) $this->config->get('app.url', ''), '/'),
            'request' => ['path' => '/', 'query' => []],
        ];
        $notifySubject = self::notifySubject($row);
        $confirmSubject = 'Ihre Anfrage bei der Hausverwaltung Müller GmbH';

        return [
            [
                'kind' => 'lead_notify',
                'to' => null,
                'subject' => $notifySubject,
                'html' => $this->view->render('emails/lead-benachrichtigung.html.twig', $vars + ['betreff' => $notifySubject]),
                'text' => $this->view->render('emails/lead-benachrichtigung.txt.twig', $vars + ['betreff' => $notifySubject]),
            ],
            [
                'kind' => 'lead_confirmation',
                'to' => (string) $row['contact_email'],
                'subject' => $confirmSubject,
                'html' => $this->view->render('emails/lead-bestaetigung.html.twig', $vars + ['betreff' => $confirmSubject]),
                'text' => $this->view->render('emails/lead-bestaetigung.txt.twig', $vars + ['betreff' => $confirmSubject]),
            ],
        ];
    }

    /**
     * Anrede der Eingangsbestätigung: "Sehr geehrte Frau X," bzw. "Sehr geehrter Herr X,", sonst "Guten Tag Vorname Nachname,".
     *
     * @param array<string, mixed> $row
     */
    public static function salutationLine(array $row): string
    {
        $last = trim((string) ($row['contact_last_name'] ?? ''));
        $first = trim((string) ($row['contact_first_name'] ?? ''));
        $salutation = (string) ($row['contact_salutation'] ?? '');
        if ($last !== '' && $salutation === 'frau') {
            return 'Sehr geehrte Frau ' . $last . ',';
        }
        if ($last !== '' && $salutation === 'herr') {
            return 'Sehr geehrter Herr ' . $last . ',';
        }
        $name = trim($first . ' ' . $last);

        return $name === '' ? 'Guten Tag,' : 'Guten Tag ' . $name . ',';
    }

    /**
     * JSON für n8n. Der Body wird beim Einreihen erzeugt und bei Wiederholungen unverändert gesendet.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $tiers
     */
    private function webhookBody(array $row, array $tiers): string
    {
        $created = new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC'));
        $data = [
            'event' => 'lead.created',
            'lead' => [
                'uuid' => $row['uuid'],
                'created_at' => $created->format('Y-m-d\TH:i:s\Z'),
                'status' => $row['status'],
                'management_form' => $row['management_form'],
                'object' => [
                    'street' => $row['object_street'] ?? null,
                    'zip' => $row['object_zip'] ?? null,
                    'city' => $row['object_city'] ?? null,
                    'year_of_construction' => $row['year_of_construction'] ?? null,
                    'units_residential' => (int) $row['units_residential'],
                    'units_commercial' => (int) $row['units_commercial'],
                    'units_parking' => (int) $row['units_parking'],
                ],
                'management_start' => $row['management_start'] ?? null,
                'has_current_manager' => $row['has_current_manager'] ?? null,
                'contact' => [
                    'salutation' => $row['contact_salutation'] ?? null,
                    'first_name' => $row['contact_first_name'] ?? null,
                    'last_name' => $row['contact_last_name'] ?? null,
                    'email' => $row['contact_email'] ?? null,
                    'phone' => $row['contact_phone'] ?? null,
                    'role' => $row['contact_role'] ?? null,
                ],
                'message' => $row['message'] ?? null,
                'attribution' => [
                    'source' => $row['source'] ?? null,
                    'utm_source' => $row['utm_source'] ?? null,
                    'utm_medium' => $row['utm_medium'] ?? null,
                    'utm_campaign' => $row['utm_campaign'] ?? null,
                    'utm_term' => $row['utm_term'] ?? null,
                    'utm_content' => $row['utm_content'] ?? null,
                    'landing_page' => $row['landing_page'] ?? null,
                    'referrer' => $row['referrer'] ?? null,
                    'region' => $row['region'] ?? null,
                ],
                'price_tiers' => [
                    'residential_id' => $tiers['residential'],
                    'commercial_id' => $tiers['commercial'],
                    'parking_id' => $tiers['parking'],
                ],
                'consent' => [
                    'text_version' => $row['consent_text_version'],
                    'at' => $created->format('Y-m-d\TH:i:s\Z'),
                ],
                'admin_url' => $this->adminUrl((string) $row['uuid']),
            ],
        ];

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
