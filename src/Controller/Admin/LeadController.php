<?php

declare(strict_types=1);

namespace Hvm\Controller\Admin;

use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Repository\LeadRepository;
use Hvm\Service\CsvExport;
use Hvm\Service\Dashboard;
use Hvm\Service\LeadAdminService;
use Hvm\Support\Clock;
use Hvm\Support\Container;
use Hvm\Support\Log;
use Hvm\Support\Uuid;
use Hvm\Validation\AngebotValidator;

/**
 * Leads im Admin-Bereich: Liste mit Filtern, Detail, Statuswechsel, Notiz, Zuweisung, CSV-Export.
 * Zustandsänderungen nur per POST (CSRF-Prüfung in der Middleware), danach Weiterleitung (PRG).
 */
final class LeadController
{
    public function __construct(
        private readonly AdminGate $gate,
        private readonly Container $container,
        private readonly Log $log,
    ) {
    }

    private function service(): LeadAdminService
    {
        return $this->container->get(LeadAdminService::class);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function index(Request $request, array $params = []): Response
    {
        $user = $this->gate->requireUser($request);
        if ($user instanceof Response) {
            return $user;
        }
        $filters = LeadAdminService::filters($request->query());
        $service = $this->service();
        $liste = $service->list($filters);
        $filters['seite'] = $liste['seite'];

        return $this->gate->render('admin/leads/liste.html.twig', [
            'titel' => 'Leads',
            'filter' => $filters,
            'liste' => $liste,
            'optionen' => $service->filterOptions() + self::labels(),
            'query_basis' => self::queryWithout($filters, ['seite']),
            'query_filter' => self::queryWithout($filters, ['seite', 'sort', 'richtung']),
            'admin' => ['bereich' => 'leads'],
        ], $user);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function export(Request $request, array $params = []): Response
    {
        $user = $this->gate->requireUser($request);
        if ($user instanceof Response) {
            return $user;
        }
        $filters = LeadAdminService::filters($request->query());
        $rows = $this->service()->exportRows($filters);
        $this->log->info('Admin: Lead-Export', ['admin_user_id' => $user['id'], 'anzahl' => count($rows)]);
        $name = 'leads-' . Clock::local()->format('Ymd-Hi') . '.csv';

        return AdminGate::secure(new Response(CsvExport::leads($rows), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]));
    }

    /**
     * @param array<string, mixed> $params
     */
    public function show(Request $request, array $params = []): Response
    {
        $user = $this->gate->requireUser($request);
        if ($user instanceof Response) {
            return $user;
        }
        $service = $this->service();
        $lead = $this->lead($params);
        if ($lead === null) {
            return $this->gate->render('admin/nicht-gefunden.html.twig', ['titel' => 'Lead nicht gefunden', 'admin' => ['bereich' => 'leads']], $user, 404);
        }
        $benutzer = $service->activeUsers();
        $zurueck = $request->queryValue('zurueck');

        return $this->gate->render('admin/leads/detail.html.twig', [
            'titel' => 'Lead vom ' . self::localDate((string) $lead['created_at']),
            'lead' => $lead,
            'ereignisse' => $service->events((int) $lead['id']),
            'benutzer' => $benutzer,
            'benutzer_map' => array_column($benutzer, 'email', 'id'),
            'optionen' => self::labels(),
            'zurueck' => self::safeBackLink($zurueck),
            'admin' => ['bereich' => 'leads'],
        ], $user);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function status(Request $request, array $params = []): Response
    {
        return $this->mutate($request, $params, function (array $lead, array $user) use ($request): array {
            $status = (string) $request->postValue('status', '');
            if (!in_array($status, LeadRepository::STATUS, true)) {
                return ['fehler', 'Bitte wählen Sie einen gültigen Status.'];
            }
            $changed = $this->service()->changeStatus((string) $lead['uuid'], $status, $user['id']);

            return $changed
                ? ['erfolg', 'Status geändert: ' . LeadAdminService::STATUS_LABELS[$status] . '.']
                : ['info', 'Der Status war bereits gesetzt.'];
        });
    }

    /**
     * @param array<string, mixed> $params
     */
    public function notiz(Request $request, array $params = []): Response
    {
        return $this->mutate($request, $params, function (array $lead, array $user) use ($request): array {
            $text = (string) $request->postValue('notiz', '');
            if (trim($text) === '') {
                return ['fehler', 'Bitte geben Sie eine Notiz ein.'];
            }
            if (mb_strlen($text) > LeadAdminService::NOTE_MAX) {
                return ['fehler', sprintf('Die Notiz darf höchstens %d Zeichen lang sein.', LeadAdminService::NOTE_MAX)];
            }
            if ($lead['anonymized_at'] !== null) {
                return ['fehler', 'Anonymisierte Leads können keine Notizen erhalten.'];
            }

            return $this->service()->addNote((string) $lead['uuid'], $text, $user['id'])
                ? ['erfolg', 'Notiz gespeichert.']
                : ['fehler', 'Die Notiz konnte nicht gespeichert werden.'];
        });
    }

    /**
     * @param array<string, mixed> $params
     */
    public function zuweisung(Request $request, array $params = []): Response
    {
        return $this->mutate($request, $params, function (array $lead, array $user) use ($request): array {
            $value = (string) $request->postValue('assigned_to', '');
            $assignee = $value === '' ? null : (ctype_digit($value) ? (int) $value : -1);
            if ($assignee === -1) {
                return ['fehler', 'Bitte wählen Sie einen gültigen Benutzer.'];
            }
            $changed = $this->service()->assign((string) $lead['uuid'], $assignee, $user['id']);
            if (!$changed) {
                return ['info', 'Die Zuweisung wurde nicht geändert.'];
            }

            return ['erfolg', $assignee === null ? 'Zuweisung aufgehoben.' : 'Zuweisung gespeichert.'];
        });
    }

    /**
     * @param array<string, mixed>                                          $params
     * @param callable(array<string, mixed>, array{id: int, email: string}): array{0: string, 1: string} $action
     */
    private function mutate(Request $request, array $params, callable $action): Response
    {
        $user = $this->gate->requireUser($request);
        if ($user instanceof Response) {
            return $user;
        }
        $lead = $this->lead($params);
        if ($lead === null) {
            return $this->gate->render('admin/nicht-gefunden.html.twig', ['titel' => 'Lead nicht gefunden', 'admin' => ['bereich' => 'leads']], $user, 404);
        }
        [$typ, $text] = $action($lead, $user);
        $this->gate->flash($typ, $text);
        $target = '/admin/leads/' . $lead['uuid'] . '/';
        $back = self::safeBackLink($request->postValue('zurueck'));
        if ($back !== null) {
            $target .= '?' . http_build_query(['zurueck' => $back]);
        }

        return AdminGate::secure(Response::redirect($target, 303));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function lead(array $params): ?array
    {
        $uuid = (string) ($params['uuid'] ?? '');

        return Uuid::isValid($uuid) ? $this->service()->find($uuid) : null;
    }

    /**
     * Rücksprung zur gefilterten Liste: nur relative Query auf /admin/leads/.
     */
    public static function safeBackLink(?string $value): ?string
    {
        if ($value === null || $value === '' || strlen($value) > 1000) {
            return null;
        }
        if (!str_starts_with($value, '/admin/leads/') || str_starts_with($value, '//') || preg_match('/[\r\n\\\\]/', $value) === 1) {
            return null;
        }
        $path = (string) parse_url($value, PHP_URL_PATH);

        return $path === '/admin/leads/' ? $value : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string>         $without
     * @return array<string, string>
     */
    private static function queryWithout(array $filters, array $without): array
    {
        $query = [];
        foreach ($filters as $key => $value) {
            if (in_array($key, $without, true) || $value === '' || $value === null) {
                continue;
            }
            // Standardwerte weglassen, damit die Links kurz bleiben
            if (($key === 'sort' && $value === 'eingang') || ($key === 'richtung' && $value === 'desc')) {
                continue;
            }
            $query[$key] = (string) $value;
        }

        return $query;
    }

    /**
     * @return array{status: array<string, string>, arten: array<string, string>, anreden: array<string, string>, rollen: array<string, string>, quellen_labels: array<string, string>, ereignisse: array<string, string>}
     */
    private static function labels(): array
    {
        return [
            'status' => LeadAdminService::STATUS_LABELS,
            'arten' => AngebotValidator::ARTEN,
            'anreden' => AngebotValidator::ANREDEN,
            'rollen' => AngebotValidator::ROLLEN,
            'quellen_labels' => Dashboard::SOURCE_LABELS,
            'ereignisse' => LeadAdminService::EVENT_LABELS,
        ];
    }

    private static function localDate(string $utc): string
    {
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y');
        } catch (\Exception) {
            return '';
        }
    }
}
