<?php

declare(strict_types=1);

namespace Hvm\Repository;

use DateTimeImmutable;
use Hvm\Support\Clock;
use PDO;

/**
 * Warteschlange outbox (Webhook und Mail). Sperre gegen Doppelverarbeitung über einen Status-Claim:
 * ein Worker setzt locked_by und locked_until in einer einzigen UPDATE-Anweisung und verarbeitet danach
 * nur Einträge mit seinem Token. Abgestürzte Worker geben ihre Einträge nach Ablauf von locked_until frei.
 */
final class OutboxRepository
{
    private const FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(string $typ, array $payload, ?int $leadId = null, ?DateTimeImmutable $at = null): int
    {
        $now = Clock::now();
        $this->pdo->prepare(
            'INSERT INTO outbox (typ, lead_id, payload, attempts, next_attempt_at, status, created_at) VALUES (?, ?, ?, 0, ?, ?, ?)'
        )->execute([
            $typ,
            $leadId,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ($at ?? $now)->format(self::FORMAT),
            'pending',
            $now->format(self::FORMAT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Beansprucht fällige Einträge für diesen Worker.
     *
     * @return list<array{id: int, typ: string, lead_id: ?int, payload: array<string, mixed>, attempts: int}>
     */
    public function claim(string $token, int $limit, int $lockSeconds = 300): array
    {
        $now = Clock::now();
        $stmt = $this->pdo->prepare(
            'UPDATE outbox SET locked_by = ?, locked_until = ?
             WHERE status = ? AND next_attempt_at <= ? AND (locked_until IS NULL OR locked_until < ?)
             ORDER BY next_attempt_at, id LIMIT ' . max(1, $limit)
        );
        $stmt->execute([
            $token,
            $now->modify('+' . max(30, $lockSeconds) . ' seconds')->format(self::FORMAT),
            'pending',
            $now->format(self::FORMAT),
            $now->format(self::FORMAT),
        ]);
        if ($stmt->rowCount() === 0) {
            return [];
        }

        $select = $this->pdo->prepare('SELECT id, typ, lead_id, payload, attempts FROM outbox WHERE locked_by = ? AND status = ? ORDER BY id');
        $select->execute([$token, 'pending']);
        $rows = [];
        foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payload = json_decode((string) ($row['payload'] ?? ''), true);
            $rows[] = [
                'id' => (int) $row['id'],
                'typ' => (string) $row['typ'],
                'lead_id' => $row['lead_id'] === null ? null : (int) $row['lead_id'],
                'payload' => is_array($payload) ? $payload : [],
                'attempts' => (int) $row['attempts'],
            ];
        }

        return $rows;
    }

    /**
     * Erfolgreich versendet: Payload leeren (Datensparsamkeit), Sperre lösen.
     */
    public function markSent(int $id, string $token): void
    {
        $this->pdo->prepare(
            'UPDATE outbox SET status = ?, sent_at = ?, payload = NULL, last_error = NULL, locked_by = NULL, locked_until = NULL
             WHERE id = ? AND locked_by = ?'
        )->execute(['sent', Clock::now()->format(self::FORMAT), $id, $token]);
    }

    public function reschedule(int $id, string $token, int $attempts, DateTimeImmutable $next, string $error): void
    {
        $this->pdo->prepare(
            'UPDATE outbox SET attempts = ?, next_attempt_at = ?, last_error = ?, locked_by = NULL, locked_until = NULL
             WHERE id = ? AND locked_by = ?'
        )->execute([$attempts, $next->format(self::FORMAT), mb_substr($error, 0, 500), $id, $token]);
    }

    public function markFailed(int $id, string $token, int $attempts, string $error): void
    {
        $this->pdo->prepare(
            'UPDATE outbox SET status = ?, attempts = ?, last_error = ?, locked_by = NULL, locked_until = NULL
             WHERE id = ? AND locked_by = ?'
        )->execute(['failed', $attempts, mb_substr($error, 0, 500), $id, $token]);
    }

    /**
     * Nicht versendbar, weil die Konfiguration fehlt: bleibt pending, ohne den Versuch zu zählen.
     */
    public function postpone(int $id, string $token, DateTimeImmutable $next, string $note): void
    {
        $this->pdo->prepare(
            'UPDATE outbox SET next_attempt_at = ?, last_error = ?, locked_by = NULL, locked_until = NULL
             WHERE id = ? AND locked_by = ?'
        )->execute([$next->format(self::FORMAT), mb_substr($note, 0, 500), $id, $token]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM outbox WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
