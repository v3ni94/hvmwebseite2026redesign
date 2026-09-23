<?php

declare(strict_types=1);

namespace Hvm\Repository;

use PDO;

/**
 * Lesezugriff auf price_tiers. Die Tabelle ist klein (Staffeln je Verwaltungsart und Einheitentyp).
 */
final class PriceTierRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{id: int, management_form: string, unit_type: string, units_from: int, units_to: ?int, price_net_per_unit_month: string, valid_from: string}>
     */
    public function all(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, management_form, unit_type, units_from, units_to, price_net_per_unit_month, valid_from
             FROM price_tiers ORDER BY management_form, unit_type, valid_from, units_from'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'management_form' => (string) $r['management_form'],
            'unit_type' => (string) $r['unit_type'],
            'units_from' => (int) $r['units_from'],
            'units_to' => $r['units_to'] === null ? null : (int) $r['units_to'],
            'price_net_per_unit_month' => (string) $r['price_net_per_unit_month'],
            'valid_from' => (string) $r['valid_from'],
        ], $rows);
    }
}
