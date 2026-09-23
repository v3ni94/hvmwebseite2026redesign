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
 * Kennzahlen für das Admin-Dashboard (MP 6.5): Leads je Woche (letzte 12 Wochen), je Quelle,
 * je Verwaltungsart und je Status. Ausgabe als Reihen mit Wert und Balkenlänge in Prozent,
 * die das Template als SVG-Balken und als Tabelle darstellt.
 *
 * Spam zählt nicht als Lead (Wochen, Quellen, Verwaltungsarten), erscheint aber in der Statusübersicht.
 * Wochen beginnen am Montag, maßgeblich ist die deutsche Zeit.
 */
final class Dashboard
{
    public const WEEKS = 12;
    public const ZEITRAUM_12W = '12w';
    public const ZEITRAUM_GESAMT = 'gesamt';

    public const SOURCE_LABELS = [
        Attribution::SOURCE_GOOGLE_ADS => 'Google Ads',
        Attribution::SOURCE_ORGANIC => 'Suchmaschine (organisch)',
        Attribution::SOURCE_REFERRAL => 'Verweis',
        Attribution::SOURCE_DIRECT => 'Direkt',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function weekStart(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $local = ($now ?? Clock::now())->setTimezone(new DateTimeZone('Europe/Berlin'));

        return $local->modify('monday this week')->setTime(0, 0)->modify('-' . (self::WEEKS - 1) . ' weeks');
    }

    /**
     * @return array{
     *   zeitraum: string, seit: ?string, gesamt: int,
     *   wochen: list<array{label: string, von: string, wert: int, balken: float}>,
     *   quellen: list<array{label: string, wert: int, anteil: float, balken: float}>,
     *   arten: list<array{label: string, wert: int, anteil: float, balken: float}>,
     *   status: list<array{schluessel: string, label: string, wert: int, anteil: float, balken: float}>,
     *   abschluss: array{gewonnen: int, verloren: int, quote: ?float}
     * }
     */
    public function data(string $zeitraum = self::ZEITRAUM_12W, ?DateTimeImmutable $now = null): array
    {
        $zeitraum = $zeitraum === self::ZEITRAUM_GESAMT ? self::ZEITRAUM_GESAMT : self::ZEITRAUM_12W;
        $start = self::weekStart($now);
        $startUtc = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $since = $zeitraum === self::ZEITRAUM_12W ? $startUtc : null;

        $status = $this->countBy('status', $since, true);
        $statusRows = [];
        $statusTotal = array_sum($status);
        foreach (LeadRepository::STATUS as $key) {
            $statusRows[] = ['schluessel' => $key, 'label' => LeadAdminService::STATUS_LABELS[$key], 'wert' => (int) ($status[$key] ?? 0)];
        }

        $quellen = [];
        foreach ($this->countBy('source', $since, false) as $key => $count) {
            $label = $key === '' ? 'ohne Angabe' : (self::SOURCE_LABELS[$key] ?? (string) $key);
            $quellen[] = ['label' => $label, 'wert' => $count];
        }
        usort($quellen, static fn (array $a, array $b): int => [$b['wert'], $a['label']] <=> [$a['wert'], $b['label']]);

        $arten = [];
        $artCounts = $this->countBy('management_form', $since, false);
        foreach (AngebotValidator::ARTEN as $key => $label) {
            $arten[] = ['label' => $label, 'wert' => (int) ($artCounts[$key] ?? 0)];
        }

        $gewonnen = (int) ($status['gewonnen'] ?? 0);
        $verloren = (int) ($status['verloren'] ?? 0);

        return [
            'zeitraum' => $zeitraum,
            'seit' => $zeitraum === self::ZEITRAUM_12W ? $start->format('Y-m-d') : null,
            'gesamt' => $statusTotal - (int) ($status['spam'] ?? 0),
            'wochen' => self::bars($this->weeks($start, $startUtc), false),
            'quellen' => self::bars($quellen, true),
            'arten' => self::bars($arten, true),
            'status' => self::bars($statusRows, true),
            'abschluss' => [
                'gewonnen' => $gewonnen,
                'verloren' => $verloren,
                'quote' => ($gewonnen + $verloren) > 0 ? round($gewonnen / ($gewonnen + $verloren) * 100, 1) : null,
            ],
        ];
    }

    /**
     * @param 'status'|'source'|'management_form' $column
     * @return array<string, int>
     */
    private function countBy(string $column, ?string $sinceUtc, bool $withSpam): array
    {
        $where = [];
        $params = [];
        if (!$withSpam) {
            $where[] = "status <> 'spam'";
        }
        if ($sinceUtc !== null) {
            $where[] = 'created_at >= ?';
            $params[] = $sinceUtc;
        }
        $sql = 'SELECT COALESCE(' . $column . ", '') AS schluessel, COUNT(*) AS anzahl FROM leads"
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' GROUP BY schluessel';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['schluessel']] = (int) $row['anzahl'];
        }

        return $result;
    }

    /**
     * @return list<array{label: string, von: string, wert: int}>
     */
    private function weeks(DateTimeImmutable $start, string $startUtc): array
    {
        $weeks = [];
        for ($i = 0; $i < self::WEEKS; $i++) {
            $monday = $start->modify('+' . $i . ' weeks');
            $weeks[$monday->format('o-W')] = [
                'label' => 'KW ' . $monday->format('W'),
                'von' => $monday->format('Y-m-d'),
                'wert' => 0,
            ];
        }
        $stmt = $this->pdo->prepare("SELECT created_at FROM leads WHERE status <> 'spam' AND created_at >= ?");
        $stmt->execute([$startUtc]);
        $berlin = new DateTimeZone('Europe/Berlin');
        $utc = new DateTimeZone('UTC');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $createdAt) {
            $key = (new DateTimeImmutable((string) $createdAt, $utc))->setTimezone($berlin)->format('o-W');
            if (isset($weeks[$key])) {
                $weeks[$key]['wert']++;
            }
        }

        return array_values($weeks);
    }

    /**
     * Ergänzt Balkenlänge (relativ zum größten Wert, 0 bis 100) und optional den Anteil an der Summe in Prozent.
     *
     * @template T of array{wert: int}
     * @param list<T> $rows
     * @return list<T&array{balken: float, anteil?: float}>
     */
    public static function bars(array $rows, bool $withShare): array
    {
        $max = 0;
        $sum = 0;
        foreach ($rows as $row) {
            $max = max($max, $row['wert']);
            $sum += $row['wert'];
        }
        foreach ($rows as $i => $row) {
            $rows[$i]['balken'] = $max > 0 ? round($row['wert'] / $max * 100, 2) : 0.0;
            if ($withShare) {
                $rows[$i]['anteil'] = $sum > 0 ? round($row['wert'] / $sum * 100, 1) : 0.0;
            }
        }

        return $rows;
    }
}
