<?php

declare(strict_types=1);

namespace Hvm\Service;

use DateTimeImmutable;
use Hvm\Repository\PriceTierRepository;
use Hvm\Support\Clock;
use Hvm\Support\Config;
use Hvm\Validation\AngebotValidator;

/**
 * Unverbindliche Preisindikation aus den Staffeln in price_tiers (MP 6.2).
 *
 * Staffellogik: je Verwaltungsart und Einheitentyp gilt die Preisliste mit dem jüngsten valid_from,
 * das nicht in der Zukunft liegt. Darin bestimmt die Gesamtzahl der Einheiten dieses Typs die Staffel
 * (units_from bis units_to, units_to NULL = ohne Obergrenze), und alle Einheiten des Typs werden
 * mit dem Preis dieser Staffel bewertet (entspricht einer Preisstufen-ID je Typ wie in der Altdatenbank).
 *
 * Ohne Staffeln oder bei PRICE_INDICATION_ENABLED=false wird nichts angezeigt. Preise werden nie erfunden.
 */
final class PriceIndication
{
    public const UNIT_TYPES = ['residential', 'commercial', 'parking'];

    /** @var list<array<string, mixed>>|null */
    private ?array $tiers = null;

    public function __construct(
        private readonly PriceTierRepository $repository,
        private readonly Config $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('app.price_indication', false);
    }

    /**
     * Anzeige im Formular: nur wenn freigeschaltet und Staffeln vorhanden.
     */
    public function isAvailable(): bool
    {
        return $this->isEnabled() && $this->tiers() !== [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tiers(): array
    {
        return $this->tiers ??= $this->repository->all();
    }

    /**
     * Staffel-IDs je Einheitentyp und, wenn alle belegten Typen eine Staffel haben, die monatliche Summe netto.
     *
     * @return array{residential: ?int, commercial: ?int, parking: ?int, monthly_net: ?float}
     */
    public function assign(string $form, int $residential, int $commercial, int $parking, ?DateTimeImmutable $on = null): array
    {
        return self::calculate($this->tiers(), $form, [
            'residential' => $residential,
            'commercial' => $commercial,
            'parking' => $parking,
        ], $on ?? Clock::now());
    }

    /**
     * Reine Berechnung ohne Datenbank (testbar).
     *
     * @param list<array<string, mixed>> $tiers
     * @param array<string, int>         $units Einheitentyp => Anzahl
     * @return array{residential: ?int, commercial: ?int, parking: ?int, monthly_net: ?float}
     */
    public static function calculate(array $tiers, string $form, array $units, DateTimeImmutable $on): array
    {
        $result = ['residential' => null, 'commercial' => null, 'parking' => null, 'monthly_net' => null];
        $sum = 0.0;
        $complete = true;
        $any = false;
        foreach (self::UNIT_TYPES as $type) {
            $count = (int) ($units[$type] ?? 0);
            if ($count < 1) {
                continue;
            }
            $any = true;
            $tier = self::selectTier($tiers, $form, $type, $count, $on);
            if ($tier === null) {
                $complete = false;
                continue;
            }
            $result[$type] = (int) $tier['id'];
            $sum += $count * (float) $tier['price_net_per_unit_month'];
        }
        if ($any && $complete) {
            $result['monthly_net'] = round($sum, 2);
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $tiers
     * @return array<string, mixed>|null
     */
    public static function selectTier(array $tiers, string $form, string $unitType, int $units, DateTimeImmutable $on): ?array
    {
        $day = $on->format('Y-m-d');
        $candidates = array_values(array_filter(
            $tiers,
            static fn (array $t): bool => $t['management_form'] === $form
                && $t['unit_type'] === $unitType
                && (string) $t['valid_from'] <= $day
        ));
        if ($candidates === []) {
            return null;
        }
        $latest = max(array_map(static fn (array $t): string => (string) $t['valid_from'], $candidates));
        foreach ($candidates as $tier) {
            if ((string) $tier['valid_from'] !== $latest) {
                continue;
            }
            $from = (int) $tier['units_from'];
            $to = $tier['units_to'] === null ? null : (int) $tier['units_to'];
            if ($units >= $from && ($to === null || $units <= $to)) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * Aktuell gültige Staffeln für die clientseitige Anzeige (ohne IDs und Altdaten).
     *
     * @return list<array{art: string, typ: string, von: int, bis: ?int, preis: float}>
     */
    public function clientTiers(?DateTimeImmutable $on = null): array
    {
        $on ??= Clock::now();
        $result = [];
        $day = $on->format('Y-m-d');
        $tiers = $this->tiers();
        foreach (array_keys(AngebotValidator::ARTEN) as $form) {
            foreach (self::UNIT_TYPES as $type) {
                $matching = array_filter($tiers, static fn (array $t): bool => $t['management_form'] === $form && $t['unit_type'] === $type && (string) $t['valid_from'] <= $day);
                if ($matching === []) {
                    continue;
                }
                $latest = max(array_map(static fn (array $t): string => (string) $t['valid_from'], $matching));
                foreach ($matching as $t) {
                    if ((string) $t['valid_from'] === $latest) {
                        $result[] = [
                            'art' => $form,
                            'typ' => $type,
                            'von' => (int) $t['units_from'],
                            'bis' => $t['units_to'] === null ? null : (int) $t['units_to'],
                            'preis' => (float) $t['price_net_per_unit_month'],
                        ];
                    }
                }
            }
        }

        return $result;
    }
}
