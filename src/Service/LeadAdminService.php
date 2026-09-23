<?php

declare(strict_types=1);

namespace Hvm\Service;

use DateTimeImmutable;
use DateTimeZone;
use Hvm\Repository\LeadRepository;
use Hvm\Support\Clock;
use Hvm\Validation\AngebotValidator;
use PDO;

/**
 * Lead-Verwaltung im Admin-Bereich (MP 6.5): Liste mit Filtern, Sortierung und Paginierung,
 * Detail mit Verlauf, Statuswechsel, Notizen, Zuweisung und Export.
 *
 * Filter und Sortierung kommen ausschließlich über feste Listen in das SQL, Werte immer als Parameter.
 */
final class LeadAdminService
{
    public const PER_PAGE = 25;
    public const EXPORT_LIMIT = 20000;
    public const NOTE_MAX = 5000;

    public const STATUS_LABELS = [
        'neu' => 'Neu',
        'kontaktiert' => 'Kontaktiert',
        'angebot' => 'Angebot',
        'gewonnen' => 'Gewonnen',
        'verloren' => 'Verloren',
        'spam' => 'Spam',
    ];

    /** Filterwert für "alle Status einschließlich Spam". Ohne Statusfilter wird Spam ausgeblendet. */
    public const STATUS_ALL = 'alle';
    public const SOURCE_NONE = '(ohne)';

    /** Sortierschlüssel => SQL-Ausdruck */
    public const SORTS = [
        'eingang' => 'l.created_at',
        'status' => 'l.status',
        'art' => 'l.management_form',
        'plz' => 'l.object_zip',
        'quelle' => 'l.source',
        'einheiten' => '(l.units_residential + l.units_commercial)',
    ];

    public const EVENT_LABELS = [
        'eingang' => 'Eingang über das Formular',
        'import' => 'Import aus dem Altbestand',
        'status' => 'Statuswechsel',
        'notiz' => 'Notiz',
        'zuweisung' => 'Zuweisung',
        'anonymisiert' => 'Anonymisiert (Löschkonzept)',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Normalisiert Filter aus der Query. Unbekannte Werte werden verworfen.
     *
     * @param array<string, mixed> $query
     * @return array{status: string, art: string, region: string, quelle: string, von: string, bis: string, sort: string, richtung: string, seite: int}
     */
    public static function filters(array $query): array
    {
        $str = static fn (string $key, int $max): string => is_string($query[$key] ?? null) ? mb_substr(trim((string) $query[$key]), 0, $max) : '';
        $status = $str('status', 20);
        if ($status !== self::STATUS_ALL && !in_array($status, LeadRepository::STATUS, true)) {
            $status = '';
        }
        $art = $str('art', 10);
        if (!isset(AngebotValidator::ARTEN[$art])) {
            $art = '';
        }
        $sort = $str('sort', 20);
        if (!isset(self::SORTS[$sort])) {
            $sort = 'eingang';
        }
        $richtung = $str('richtung', 4) === 'asc' ? 'asc' : 'desc';
        $seite = is_string($query['seite'] ?? null) && ctype_digit((string) $query['seite']) ? max(1, min(100000, (int) $query['seite'])) : 1;

        return [
            'status' => $status,
            'art' => $art,
            'region' => $str('region', 100),
            'quelle' => $str('quelle', 50),
            'von' => self::isoDate($str('von', 10)),
            'bis' => self::isoDate($str('bis', 10)),
            'sort' => $sort,
            'richtung' => $richtung,
            'seite' => $seite,
        ];
    }

    /**
     * Akzeptiert JJJJ-MM-TT (input type=date) und TT.MM.JJJJ, liefert JJJJ-MM-TT oder ''.
     */
    public static function isoDate(string $value): string
    {
        foreach (['!Y-m-d', '!d.m.Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false && $date->format(substr($format, 1)) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>} WHERE-Klausel und Parameter
     */
    private function where(array $filters): array
    {
        $where = [];
        $params = [];
        $status = (string) ($filters['status'] ?? '');
        if ($status === '') {
            $where[] = "l.status <> 'spam'";
        } elseif ($status !== self::STATUS_ALL) {
            $where[] = 'l.status = ?';
            $params[] = $status;
        }
        if (($filters['art'] ?? '') !== '') {
            $where[] = 'l.management_form = ?';
            $params[] = $filters['art'];
        }
        $region = (string) ($filters['region'] ?? '');
        if ($region !== '') {
            if (preg_match('/^\d{1,5}$/', $region) === 1) {
                $where[] = 'l.object_zip LIKE ?';
                $params[] = $region . '%';
            } else {
                $like = '%' . addcslashes($region, '\\%_') . '%';
                $where[] = '(l.object_city LIKE ? OR l.region LIKE ?)';
                $params[] = $like;
                $params[] = $like;
            }
        }
        $quelle = (string) ($filters['quelle'] ?? '');
        if ($quelle === self::SOURCE_NONE) {
            $where[] = "(l.source IS NULL OR l.source = '')";
        } elseif ($quelle !== '') {
            $where[] = 'l.source = ?';
            $params[] = $quelle;
        }
        $berlin = new DateTimeZone('Europe/Berlin');
        $utc = new DateTimeZone('UTC');
        if (($filters['von'] ?? '') !== '') {
            $von = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $filters['von'], $berlin);
            if ($von !== false) {
                $where[] = 'l.created_at >= ?';
                $params[] = $von->setTimezone($utc)->format('Y-m-d H:i:s');
            }
        }
        if (($filters['bis'] ?? '') !== '') {
            $bis = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $filters['bis'], $berlin);
            if ($bis !== false) {
                $where[] = 'l.created_at < ?';
                $params[] = $bis->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
            }
        }

        return [$where === [] ? '1=1' : implode(' AND ', $where), $params];
    }

    private function orderBy(array $filters): string
    {
        $expr = self::SORTS[$filters['sort'] ?? 'eingang'] ?? self::SORTS['eingang'];
        $dir = ($filters['richtung'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        return $expr . ' ' . $dir . ', l.id ' . $dir;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int, seite: int, seiten: int, pro_seite: int}
     */
    public function list(array $filters, int $perPage = self::PER_PAGE): array
    {
        [$where, $params] = $this->where($filters);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM leads l WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $seiten = max(1, (int) ceil($total / $perPage));
        $seite = min(max(1, (int) ($filters['seite'] ?? 1)), $seiten);
        $offset = ($seite - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            'SELECT l.id, l.uuid, l.created_at, l.status, l.management_form, l.contact_first_name, l.contact_last_name,
                    l.object_zip, l.object_city, l.region, l.units_residential, l.units_commercial, l.units_parking,
                    l.source, l.assigned_to, l.legacy_id, l.anonymized_at, a.email AS assigned_to_email
             FROM leads l LEFT JOIN admin_users a ON a.id = l.assigned_to
             WHERE ' . $where . ' ORDER BY ' . $this->orderBy($filters)
            . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
        );
        $stmt->execute($params);

        return [
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'seite' => $seite,
            'seiten' => $seiten,
            'pro_seite' => $perPage,
        ];
    }

    /**
     * Alle Leads der gefilterten Liste (ohne Paginierung) für den CSV-Export.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function exportRows(array $filters): array
    {
        [$where, $params] = $this->where($filters);
        $stmt = $this->pdo->prepare(
            'SELECT l.*, a.email AS assigned_to_email FROM leads l LEFT JOIN admin_users a ON a.id = l.assigned_to
             WHERE ' . $where . ' ORDER BY ' . $this->orderBy($filters) . ' LIMIT ' . self::EXPORT_LIMIT
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array{quellen: list<string>, benutzer: list<array{id: int, email: string}>}
     */
    public function filterOptions(): array
    {
        $quellen = $this->pdo->query("SELECT DISTINCT source FROM leads WHERE source IS NOT NULL AND source <> '' ORDER BY source")
            ->fetchAll(PDO::FETCH_COLUMN);

        return ['quellen' => array_map('strval', $quellen), 'benutzer' => $this->activeUsers()];
    }

    /**
     * @return list<array{id: int, email: string}>
     */
    public function activeUsers(): array
    {
        $rows = $this->pdo->query('SELECT id, email FROM admin_users WHERE disabled_at IS NULL ORDER BY email')->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'email' => (string) $r['email']], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.*, a.email AS assigned_to_email FROM leads l LEFT JOIN admin_users a ON a.id = l.assigned_to WHERE l.uuid = ?'
        );
        $stmt->execute([$uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Verlauf mit E-Mail des handelnden Benutzers, neueste zuerst.
     *
     * @return list<array<string, mixed>>
     */
    public function events(int $leadId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.*, a.email AS admin_email FROM lead_events e LEFT JOIN admin_users a ON a.id = e.admin_user_id
             WHERE e.lead_id = ? ORDER BY e.created_at DESC, e.id DESC'
        );
        $stmt->execute([$leadId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Statuswechsel mit Ereignis (von, nach, Benutzer). Liefert false, wenn Lead oder Status ungültig
     * sind oder sich nichts ändert.
     */
    public function changeStatus(string $uuid, string $status, int $adminUserId): bool
    {
        if (!in_array($status, LeadRepository::STATUS, true)) {
            return false;
        }

        return $this->inTransaction(function () use ($uuid, $status, $adminUserId): bool {
            $lead = $this->lockLead($uuid);
            if ($lead === null || $lead['status'] === $status) {
                return false;
            }
            $now = Clock::now()->format('Y-m-d H:i:s');
            $this->pdo->prepare('UPDATE leads SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, $now, (int) $lead['id']]);
            $this->addEvent((int) $lead['id'], 'status', (string) $lead['status'], $status, $adminUserId, null);

            return true;
        });
    }

    /**
     * Neue Notiz als Ereignis (nur anhängen, nicht überschreiben).
     */
    public function addNote(string $uuid, string $text, int $adminUserId): bool
    {
        $text = trim(str_replace("\0", '', $text));
        if ($text === '' || mb_strlen($text) > self::NOTE_MAX) {
            return false;
        }

        return $this->inTransaction(function () use ($uuid, $text, $adminUserId): bool {
            $lead = $this->lockLead($uuid);
            if ($lead === null || $lead['anonymized_at'] !== null) {
                return false;
            }
            $this->pdo->prepare('UPDATE leads SET updated_at = ? WHERE id = ?')->execute([Clock::now()->format('Y-m-d H:i:s'), (int) $lead['id']]);
            $this->addEvent((int) $lead['id'], 'notiz', null, null, $adminUserId, $text);

            return true;
        });
    }

    /**
     * Zuweisung an einen aktiven Benutzer oder Aufhebung (null). Das Ereignis speichert die Benutzer-ID in notiz.
     */
    public function assign(string $uuid, ?int $assigneeId, int $adminUserId): bool
    {
        if ($assigneeId !== null && !in_array($assigneeId, array_column($this->activeUsers(), 'id'), true)) {
            return false;
        }

        return $this->inTransaction(function () use ($uuid, $assigneeId, $adminUserId): bool {
            $lead = $this->lockLead($uuid);
            if ($lead === null) {
                return false;
            }
            $current = $lead['assigned_to'] === null ? null : (int) $lead['assigned_to'];
            if ($current === $assigneeId) {
                return false;
            }
            $this->pdo->prepare('UPDATE leads SET assigned_to = ?, updated_at = ? WHERE id = ?')
                ->execute([$assigneeId, Clock::now()->format('Y-m-d H:i:s'), (int) $lead['id']]);
            $this->addEvent((int) $lead['id'], 'zuweisung', null, null, $adminUserId, $assigneeId === null ? null : (string) $assigneeId);

            return true;
        });
    }

    private function addEvent(int $leadId, string $typ, ?string $von, ?string $nach, ?int $adminUserId, ?string $notiz): void
    {
        (new LeadRepository($this->pdo))->addEvent($leadId, $typ, $von, $nach, $adminUserId, $notiz);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lockLead(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, status, assigned_to, anonymized_at FROM leads WHERE uuid = ? FOR UPDATE');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function inTransaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
