<?php

declare(strict_types=1);

namespace Hvm\Controller;

use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Support\Config;
use Hvm\Support\Db;
use Hvm\Support\Log;
use PDO;
use Throwable;

/**
 * Betriebs-Endpunkt /health für den Docker-Healthcheck und externes Monitoring (docs/betrieb.md Abschnitt 4).
 *
 * Antwort immer 200, solange Nginx und PHP-FPM antworten: {"status": "ok"|"eingeschraenkt", "datenbank": "ja"|"nein"}.
 * Ein Datenbankausfall macht den Container bewusst nicht "unhealthy": Traefik nimmt ungesunde Container aus dem
 * Routing, die Inhaltsseiten funktionieren aber auch ohne Datenbank. Keine Versions-, Pfad- oder Fehlerangaben.
 */
final class HealthController
{
    /** Sekunden bis zum Abbruch des Verbindungsaufbaus, damit der Healthcheck nicht hängt */
    public const DB_TIMEOUT = 2;

    public function __construct(
        private readonly Config $config,
        private readonly Log $log,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function show(Request $request, array $params = []): Response
    {
        $datenbank = $this->databaseReachable();

        return Response::json([
            'status' => $datenbank ? 'ok' : 'eingeschraenkt',
            'datenbank' => $datenbank ? 'ja' : 'nein',
        ])
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function databaseReachable(): bool
    {
        try {
            $pdo = Db::fromConfig($this->config, 'db', [PDO::ATTR_TIMEOUT => self::DB_TIMEOUT]);

            return (int) $pdo->query('SELECT 1')->fetchColumn() === 1;
        } catch (Throwable $e) {
            $this->log->warning('Healthcheck: Datenbank nicht erreichbar', ['fehler' => get_class($e)]);

            return false;
        }
    }
}
