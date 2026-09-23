<?php

declare(strict_types=1);

namespace Hvm\Service;

use DateTimeImmutable;
use Hvm\Support\Clock;
use Hvm\Support\Config;
use PDO;

/**
 * Löschkonzept (MP 6.5, docs/loeschkonzept.md): entfernt personenbezogene Daten nach LEAD_RETENTION_DAYS.
 *
 * - Leads mit Status spam: werden gelöscht (Verlauf und Outbox über Fremdschlüssel mit).
 * - Leads mit Status verloren: werden anonymisiert. Es bleiben Verwaltungsart, Einheiten, Baujahr,
 *   PLZ-Bereich (zwei Ziffern), Betreuungsgebiet, Kanalzuordnung und Status für die Statistik.
 * - Kontaktanfragen mit Status erledigt oder spam: werden gelöscht.
 * - Bewerbungen mit Status abgeschlossen oder spam: verschlüsselte Datei und Datensatz werden gelöscht.
 * - Outbox: Nutzdaten fehlgeschlagener oder versendeter Einträge werden geleert.
 *
 * Stichtag je Datensatz: letzter Statuswechsel (lead_events), sonst updated_at. Ohne Frist (null oder 0)
 * ist der Löschlauf deaktiviert. Ergebnis und Protokoll enthalten nur Zählwerte.
 */
final class Retention
{
    public const BATCH = 500;

    /** Spalten, die bei der Anonymisierung eines Leads geleert werden */
    public const ANONYMIZE_NULL = [
        'contact_salutation', 'contact_first_name', 'contact_last_name', 'contact_email', 'contact_phone', 'contact_role',
        'contact_street', 'contact_zip', 'contact_city', 'object_street', 'object_city', 'message', 'notes', 'referrer',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
    }

    public function configuredDays(): ?int
    {
        $days = $this->config->get('app.lead_retention_days');

        return is_numeric($days) && (int) $days > 0 ? (int) $days : null;
    }

    /**
     * @return array{aktiv: bool, dry_run: bool, tage: ?int, stichtag: ?string, ergebnisse: array<string, int>}
     */
    public function run(?int $days, bool $dryRun, ?DateTimeImmutable $now = null): array
    {
        if ($days === null || $days <= 0) {
            return ['aktiv' => false, 'dry_run' => $dryRun, 'tage' => null, 'stichtag' => null, 'ergebnisse' => []];
        }
        $cutoff = ($now ?? Clock::now())->modify('-' . $days . ' days')->format('Y-m-d H:i:s');

        $ergebnisse = [
            'leads_spam_geloescht' => $this->deleteSpamLeads($cutoff, $dryRun),
            'leads_verloren_anonymisiert' => $this->anonymizeLostLeads($cutoff, $dryRun),
            'kontaktanfragen_geloescht' => $this->deleteSimple('contact_requests', ['erledigt', 'spam'], $cutoff, $dryRun),
        ];
        $bewerbungen = $this->deleteApplications($cutoff, $dryRun);
        $ergebnisse += $bewerbungen;
        $ergebnisse['outbox_bereinigt'] = $this->clearOutbox($cutoff, $dryRun);

        return ['aktiv' => true, 'dry_run' => $dryRun, 'tage' => $days, 'stichtag' => $cutoff, 'ergebnisse' => $ergebnisse];
    }

    private function leadReferenceSql(): string
    {
        return 'COALESCE((SELECT MAX(e.created_at) FROM lead_events e WHERE e.lead_id = l.id AND e.typ = \'status\'), l.updated_at, l.created_at)';
    }

    /**
     * @return list<int>
     */
    private function leadIds(string $status, string $cutoff, bool $onlyNotAnonymized, int $limit): array
    {
        $sql = 'SELECT l.id FROM leads l WHERE l.status = ? AND ' . $this->leadReferenceSql() . ' < ?'
            . ($onlyNotAnonymized ? ' AND l.anonymized_at IS NULL' : '')
            . ' ORDER BY l.id LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$status, $cutoff]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function countLeads(string $status, string $cutoff, bool $onlyNotAnonymized): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM leads l WHERE l.status = ? AND ' . $this->leadReferenceSql() . ' < ?'
            . ($onlyNotAnonymized ? ' AND l.anonymized_at IS NULL' : '')
        );
        $stmt->execute([$status, $cutoff]);

        return (int) $stmt->fetchColumn();
    }

    private function deleteSpamLeads(string $cutoff, bool $dryRun): int
    {
        if ($dryRun) {
            return $this->countLeads('spam', $cutoff, false);
        }
        $total = 0;
        while (($ids = $this->leadIds('spam', $cutoff, false, self::BATCH)) !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $this->pdo->beginTransaction();
            try {
                // lead_events und outbox hängen mit ON DELETE CASCADE am Lead
                $stmt = $this->pdo->prepare('DELETE FROM leads WHERE id IN (' . $in . ')');
                $stmt->execute($ids);
                $total += $stmt->rowCount();
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }

        return $total;
    }

    private function anonymizeLostLeads(string $cutoff, bool $dryRun): int
    {
        if ($dryRun) {
            return $this->countLeads('verloren', $cutoff, true);
        }
        $now = Clock::now()->format('Y-m-d H:i:s');
        $set = implode(', ', array_map(static fn (string $c): string => $c . ' = NULL', self::ANONYMIZE_NULL));
        $total = 0;
        while (($ids = $this->leadIds('verloren', $cutoff, true, self::BATCH)) !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $this->pdo->beginTransaction();
            try {
                $stmt = $this->pdo->prepare(
                    'UPDATE leads SET ' . $set . ', object_zip = IF(object_zip IS NULL OR object_zip = \'\', NULL, LEFT(object_zip, 2)),
                     anonymized_at = ?, updated_at = ? WHERE id IN (' . $in . ')'
                );
                $stmt->execute(array_merge([$now, $now], $ids));
                $total += $stmt->rowCount();
                // Freitext im Verlauf (Notizen) kann personenbezogene Daten enthalten
                $this->pdo->prepare('UPDATE lead_events SET notiz = NULL WHERE lead_id IN (' . $in . ')')->execute($ids);
                $this->pdo->prepare('DELETE FROM outbox WHERE lead_id IN (' . $in . ')')->execute($ids);
                $insert = $this->pdo->prepare('INSERT INTO lead_events (lead_id, typ, created_at) VALUES (?, \'anonymisiert\', ?)');
                foreach ($ids as $id) {
                    $insert->execute([$id, $now]);
                }
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }

        return $total;
    }

    /**
     * @param 'contact_requests' $table
     * @param list<string>       $statuses
     */
    private function deleteSimple(string $table, array $statuses, string $cutoff, bool $dryRun): int
    {
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $params = array_merge($statuses, [$cutoff]);
        if ($dryRun) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE status IN (' . $in . ') AND updated_at < ?');
            $stmt->execute($params);

            return (int) $stmt->fetchColumn();
        }
        $stmt = $this->pdo->prepare('DELETE FROM ' . $table . ' WHERE status IN (' . $in . ') AND updated_at < ?');
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * @return array{bewerbungen_geloescht: int, dateien_geloescht: int, dateien_fehlend: int, dateien_fehler: int}
     */
    private function deleteApplications(string $cutoff, bool $dryRun): array
    {
        $result = ['bewerbungen_geloescht' => 0, 'dateien_geloescht' => 0, 'dateien_fehlend' => 0, 'dateien_fehler' => 0];
        $stmt = $this->pdo->prepare(
            "SELECT id, datei_pfad FROM job_applications WHERE status IN ('abgeschlossen', 'spam') AND updated_at < ? ORDER BY id"
        );
        $stmt->execute([$cutoff]);
        $delete = $this->pdo->prepare('DELETE FROM job_applications WHERE id = ?');

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $path = $row['datei_pfad'] === null || $row['datei_pfad'] === '' ? null : $this->uploadPath((string) $row['datei_pfad']);
            if ($row['datei_pfad'] !== null && $row['datei_pfad'] !== '' && $path === null) {
                // Pfad außerhalb von storage/uploads: nicht anfassen, Datensatz bleibt zur Prüfung erhalten
                $result['dateien_fehler']++;
                continue;
            }
            if ($path !== null) {
                if (!is_file($path)) {
                    $result['dateien_fehlend']++;
                } elseif ($dryRun) {
                    $result['dateien_geloescht']++;
                } elseif (@unlink($path)) {
                    $result['dateien_geloescht']++;
                } else {
                    $result['dateien_fehler']++;
                    continue;
                }
            }
            if (!$dryRun) {
                $delete->execute([(int) $row['id']]);
            }
            $result['bewerbungen_geloescht']++;
        }

        return $result;
    }

    /**
     * Absoluter Pfad einer abgelegten Datei, nur innerhalb von storage/uploads, sonst null.
     */
    public function uploadPath(string $relative): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if (!str_starts_with($relative, 'storage/uploads/') || preg_match('#(^|/)\.\.?(/|$)#', $relative) === 1 || str_contains($relative, "\0")) {
            return null;
        }

        return rtrim((string) $this->config->get('app.base_path'), '/') . '/' . $relative;
    }

    private function clearOutbox(string $cutoff, bool $dryRun): int
    {
        $where = "payload IS NOT NULL AND status IN ('failed', 'sent') AND created_at < ?";
        if ($dryRun) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM outbox WHERE ' . $where);
            $stmt->execute([$cutoff]);

            return (int) $stmt->fetchColumn();
        }
        $stmt = $this->pdo->prepare('UPDATE outbox SET payload = NULL WHERE ' . $where);
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }
}
