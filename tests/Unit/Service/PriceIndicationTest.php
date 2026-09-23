<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use Hvm\Service\PriceIndication;
use PHPUnit\Framework\TestCase;

/**
 * Staffelwahl mit fiktiven Testwerten. Die Werte existieren nur in diesem Test, nicht in Seeds oder Migrationen.
 */
final class PriceIndicationTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function staffeln(): array
    {
        $t = static fn (int $id, string $art, string $typ, int $von, ?int $bis, string $preis, string $ab): array => [
            'id' => $id, 'management_form' => $art, 'unit_type' => $typ, 'units_from' => $von, 'units_to' => $bis,
            'price_net_per_unit_month' => $preis, 'valid_from' => $ab,
        ];

        return [
            $t(1, 'weg', 'residential', 1, 10, '10.00', '2020-01-01'),
            $t(2, 'weg', 'residential', 11, 50, '8.00', '2020-01-01'),
            $t(3, 'weg', 'residential', 51, null, '6.00', '2020-01-01'),
            // neue Preisliste ab 2027 ersetzt die alte vollständig
            $t(4, 'weg', 'residential', 1, 20, '11.00', '2027-01-01'),
            $t(5, 'weg', 'residential', 21, null, '9.00', '2027-01-01'),
            $t(6, 'weg', 'commercial', 1, null, '15.00', '2020-01-01'),
            $t(7, 'weg', 'parking', 1, null, '2.50', '2020-01-01'),
            $t(8, 'miet', 'residential', 1, null, '20.00', '2020-01-01'),
        ];
    }

    private function am(string $datum): DateTimeImmutable
    {
        return new DateTimeImmutable($datum, new DateTimeZone('UTC'));
    }

    public function testSelectsTierByUnitCount(): void
    {
        $tiers = $this->staffeln();
        $tag = $this->am('2026-09-23');
        self::assertSame(1, PriceIndication::selectTier($tiers, 'weg', 'residential', 1, $tag)['id'] ?? null);
        self::assertSame(1, PriceIndication::selectTier($tiers, 'weg', 'residential', 10, $tag)['id'] ?? null);
        self::assertSame(2, PriceIndication::selectTier($tiers, 'weg', 'residential', 11, $tag)['id'] ?? null);
        self::assertSame(3, PriceIndication::selectTier($tiers, 'weg', 'residential', 500, $tag)['id'] ?? null);
    }

    public function testUsesLatestValidPriceList(): void
    {
        $tiers = $this->staffeln();
        self::assertSame(2, PriceIndication::selectTier($tiers, 'weg', 'residential', 15, $this->am('2026-12-31'))['id'] ?? null);
        self::assertSame(4, PriceIndication::selectTier($tiers, 'weg', 'residential', 15, $this->am('2027-01-01'))['id'] ?? null);
        self::assertSame(5, PriceIndication::selectTier($tiers, 'weg', 'residential', 60, $this->am('2027-06-01'))['id'] ?? null);
    }

    public function testNoTierForUnknownFormOrType(): void
    {
        $tiers = $this->staffeln();
        self::assertNull(PriceIndication::selectTier($tiers, 'se', 'residential', 5, $this->am('2026-09-23')));
        self::assertNull(PriceIndication::selectTier($tiers, 'miet', 'parking', 5, $this->am('2026-09-23')));
        self::assertNull(PriceIndication::selectTier([], 'weg', 'residential', 5, $this->am('2026-09-23')));
    }

    public function testCalculateSumsAllUnitTypes(): void
    {
        $result = PriceIndication::calculate($this->staffeln(), 'weg', ['residential' => 12, 'commercial' => 2, 'parking' => 4], $this->am('2026-09-23'));
        self::assertSame(2, $result['residential']);
        self::assertSame(6, $result['commercial']);
        self::assertSame(7, $result['parking']);
        // 12 x 8,00 + 2 x 15,00 + 4 x 2,50 = 96 + 30 + 10 = 136
        self::assertSame(136.0, $result['monthly_net']);
    }

    public function testNoSumWhenAUsedTypeHasNoTier(): void
    {
        $result = PriceIndication::calculate($this->staffeln(), 'miet', ['residential' => 3, 'parking' => 2], $this->am('2026-09-23'));
        self::assertSame(8, $result['residential']);
        self::assertNull($result['parking']);
        self::assertNull($result['monthly_net']);
    }

    public function testNoUnitsNoIndication(): void
    {
        $result = PriceIndication::calculate($this->staffeln(), 'weg', [], $this->am('2026-09-23'));
        self::assertNull($result['monthly_net']);
    }
}
