<?php

declare(strict_types=1);

namespace Hvm\Repository;

use Hvm\Support\Clock;
use PDO;

/**
 * Zugriff auf leads und lead_events. Ausschließlich Prepared Statements, Spaltennamen aus fester Liste.
 */
final class LeadRepository
{
    public const STATUS = ['neu', 'kontaktiert', 'angebot', 'gewonnen', 'verloren', 'spam'];

    private const COLUMNS = [
        'uuid', 'created_at', 'updated_at', 'management_form',
        'contact_salutation', 'contact_first_name', 'contact_last_name', 'contact_email', 'contact_phone', 'contact_role',
        'contact_street', 'contact_zip', 'contact_city',
        'object_street', 'object_zip', 'object_city', 'year_of_construction',
        'units_residential', 'units_commercial', 'units_parking',
        'management_start', 'has_current_manager', 'message',
        'price_tier_residential_id', 'price_tier_commercial_id', 'price_tier_parking_id',
        'source', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'landing_page', 'referrer', 'region',
        'consent_text_version', 'consent_at', 'status', 'assigned_to', 'notes', 'legacy_id',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Legt einen Lead an. Unbekannte Schlüssel werden ignoriert.
     *
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        $now = Clock::now()->format('Y-m-d H:i:s');
        $row += ['created_at' => $now, 'updated_at' => $now, 'status' => 'neu'];
        $data = array_intersect_key($row, array_flip(self::COLUMNS));
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $data[$key] = $value ? 1 : 0;
            }
        }
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO leads (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $this->pdo->prepare($sql)->execute(array_values($data));

        return (int) $this->pdo->lastInsertId();
    }

    public function addEvent(int $leadId, string $typ, ?string $vonStatus = null, ?string $nachStatus = null, ?int $adminUserId = null, ?string $notiz = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO lead_events (lead_id, typ, von_status, nach_status, admin_user_id, notiz, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$leadId, $typ, $vonStatus, $nachStatus, $adminUserId, $notiz, Clock::now()->format('Y-m-d H:i:s')]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM leads WHERE uuid = ?');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(int $leadId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM lead_events WHERE lead_id = ? ORDER BY id');
        $stmt->execute([$leadId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
