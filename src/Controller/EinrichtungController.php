<?php

declare(strict_types=1);

namespace Hvm\Controller;

use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Http\Session;
use Hvm\Security\AdminProvisioning;
use Hvm\Security\FileRateLimiter;
use Hvm\Security\SpamGuard;
use Hvm\Support\Config;
use Hvm\Support\Container;
use Hvm\Support\Diagnose;
use Hvm\Support\Env;
use Hvm\Support\Log;
use Hvm\Support\Migrator;
use Hvm\View\View;
use PDO;
use Throwable;

/**
 * Web-Einrichtung /_einrichtung/ für Webhosting ohne Kommandozeile (docs/deploy-sftp.md).
 *
 * Erreichbar nur, wenn SETUP_TOKEN mindestens 32 Zeichen hat und storage/setup.lock fehlt, sonst 404
 * (in jeder Umgebung, auch Produktion). Zugang mit dem Token als Formularfeld (hash_equals), Rate Limit über
 * Dateien (funktioniert vor den Migrationen), CSRF über die globale Middleware, Sitzung mit neuer ID nach
 * erfolgreicher Anmeldung. Die Sitzung bleibt an den Token gebunden: ein geänderter Token meldet ab.
 *
 * STORAGE_MODE=datei (Standard): nur Diagnose, Hash-Hilfe und Abschluss, keine Migrationen, kein Admin, kein
 * Datenbankzugriff.
 *
 * Funktionen: Diagnose ohne Geheimnisse, Migrationen, erster Admin (TOTP-Geheimnis, otpauth-URI und
 * Wiederherstellungscodes einmalig in der Antwort, nie gespeichert oder protokolliert), Hash-Hilfe für
 * STAGING_BASIC_AUTH, Abschluss (schreibt storage/setup.lock). Nie in Sitemap, robots.txt oder llms.txt.
 */
final class EinrichtungController
{
    public const PFAD = '/_einrichtung/';
    public const LOCK_DATEI = 'setup.lock';
    public const TOKEN_MIN = Diagnose::SETUP_TOKEN_MIN;
    public const VERSUCHE = 5;
    public const FENSTER = 900;
    /** Obergrenze über alle IP-Adressen (verteilte Versuche) */
    public const VERSUCHE_GESAMT = 50;

    private const SESSION_KEY = '_einrichtung_zugang';

    public function __construct(
        private readonly Config $config,
        private readonly Container $container,
        private readonly Session $session,
        private readonly View $view,
        private readonly Log $log,
        private readonly ErrorController $errors,
    ) {
    }

    private function storage(): string
    {
        $pfad = $this->config->get('app.setup_storage');

        return is_string($pfad) && $pfad !== '' ? rtrim($pfad, '/') : rtrim((string) $this->config->get('app.base_path'), '/') . '/storage';
    }

    private function token(): string
    {
        $token = $this->config->get('app.setup_token');

        return is_string($token) ? trim($token) : '';
    }

    public function available(): bool
    {
        return strlen($this->token()) >= self::TOKEN_MIN && !is_file($this->storage() . '/' . self::LOCK_DATEI);
    }

    private function signatur(): string
    {
        return hash_hmac('sha256', 'einrichtung-zugang', $this->token());
    }

    private function angemeldet(): bool
    {
        $wert = $this->session->get(self::SESSION_KEY);

        return is_string($wert) && hash_equals($this->signatur(), $wert);
    }

    private function limiter(): FileRateLimiter
    {
        // Schlüssel aus APP_KEY, ersatzweise aus dem Token (Einrichtung ohne gültigen APP_KEY)
        $key = SpamGuard::hasAppKey($this->config) ? SpamGuard::deriveKey($this->config, 'einrichtung') : hash('sha256', 'einrichtung|' . $this->token(), true);

        return new FileRateLimiter($this->storage() . '/ratelimit', $key);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function show(Request $request, array $params = []): Response
    {
        if (!$this->available()) {
            return $this->errors->notFound($request);
        }

        return $this->angemeldet() ? $this->uebersicht([]) : $this->anmeldung(null);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function action(Request $request, array $params = []): Response
    {
        if (!$this->available()) {
            return $this->errors->notFound($request);
        }
        $aktion = (string) $request->postValue('aktion', '');
        if ($aktion === 'anmelden') {
            return $this->anmelden($request);
        }
        if (!$this->angemeldet()) {
            return $this->anmeldung('Bitte zuerst mit dem Einrichtungs-Token anmelden.', 403);
        }

        if ($this->dateiModus() && in_array($aktion, ['migrieren', 'admin'], true)) {
            return $this->uebersicht(['fehler' => 'Im Dateimodus (STORAGE_MODE=datei) gibt es keine Datenbank, keine Migrationen und keinen Admin-Bereich.'], 404);
        }

        return match ($aktion) {
            'migrieren' => $this->migrieren(),
            'admin' => $this->adminAnlegen($request),
            'hash' => $this->hashErzeugen($request),
            'abschliessen' => $this->abschliessen($request),
            'abmelden' => $this->abmelden(),
            default => $this->uebersicht(['fehler' => 'Unbekannte Aktion.'], 400),
        };
    }

    private function anmelden(Request $request): Response
    {
        $ip = $request->clientIp(array_values((array) $this->config->get('app.trusted_proxies', [])));
        $limiter = $this->limiter();
        try {
            if ($limiter->tooMany('einrichtung', $ip, self::VERSUCHE, self::FENSTER)
                || $limiter->tooMany('einrichtung-gesamt', 'alle', self::VERSUCHE_GESAMT, self::FENSTER)) {
                $this->log->warning('Einrichtung: Anmeldung gesperrt (Rate Limit)');

                return $this->anmeldung('Zu viele Versuche. Bitte in 15 Minuten erneut versuchen.', 429)
                    ->withHeader('Retry-After', (string) self::FENSTER);
            }
            $limiter->hit('einrichtung', $ip, self::FENSTER);
            $limiter->hit('einrichtung-gesamt', 'alle', self::FENSTER);
        } catch (Throwable $e) {
            // Ohne beschreibbaren Speicher kein Rate Limit, daher auch keine Anmeldung
            $this->log->error('Einrichtung: Rate Limit nicht möglich', ['fehler' => get_class($e)]);

            return $this->anmeldung('storage/ratelimit ist nicht beschreibbar. Bitte Schreibrechte für den Ordner storage setzen.', 503);
        }

        $eingabe = trim((string) $request->postValue('token', ''));
        if ($eingabe === '' || !hash_equals($this->token(), $eingabe)) {
            $this->log->warning('Einrichtung: falscher Token');

            return $this->anmeldung('Der Token ist nicht korrekt.', 422);
        }
        $limiter->reset('einrichtung', $ip);
        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, $this->signatur());
        $this->log->info('Einrichtung: angemeldet');

        return self::sicher(Response::redirect(self::PFAD, 303));
    }

    private function abmelden(): Response
    {
        $this->session->remove(self::SESSION_KEY);
        $this->session->regenerate();

        return self::sicher(Response::redirect(self::PFAD, 303));
    }

    private function dateiModus(): bool
    {
        return $this->config->get('app.storage_mode', 'datei') === 'datei';
    }

    private function pdo(): ?PDO
    {
        if ($this->dateiModus()) {
            return null;
        }
        try {
            return $this->container->get(PDO::class);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function migrieren(): Response
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return $this->uebersicht(['fehler' => 'Keine Datenbankverbindung. Zugangsdaten (DB_*) in .env prüfen.'], 503);
        }
        $zeilen = [];
        try {
            $ergebnis = (new Migrator($pdo, rtrim((string) $this->config->get('app.base_path'), '/') . '/migrations'))
                ->migrate(static function (string $zeile) use (&$zeilen): void {
                    $zeilen[] = $zeile;
                });
        } catch (Throwable $e) {
            $this->log->error('Einrichtung: Migration fehlgeschlagen', ['fehler' => get_class($e)]);

            return $this->uebersicht(['fehler' => 'Migration fehlgeschlagen (' . get_class($e) . '). Details im Log.'], 500);
        }
        if (!$ergebnis['ok']) {
            $this->log->error('Einrichtung: Migration fehlgeschlagen', ['version' => (string) $ergebnis['version']]);

            return $this->uebersicht([
                'fehler' => 'Migration ' . ($ergebnis['version'] ?? '') . ' fehlgeschlagen. Details im Log. Bereits ausgeführte Anweisungen dieser Datei vor einem neuen Versuch prüfen.',
                'migration_zeilen' => $zeilen,
            ], 500);
        }
        $this->log->info('Einrichtung: Migrationen ausgeführt', ['anzahl' => count($ergebnis['ausgefuehrt'])]);

        return $this->uebersicht([
            'meldung' => $ergebnis['ausgefuehrt'] === [] ? 'Keine offenen Migrationen.' : count($ergebnis['ausgefuehrt']) . ' Migration(en) ausgeführt.',
            'migration_zeilen' => $zeilen,
        ]);
    }

    private function adminAnlegen(Request $request): Response
    {
        $pdo = $this->pdo();
        if ($pdo === null) {
            return $this->uebersicht(['fehler' => 'Keine Datenbankverbindung. Zugangsdaten (DB_*) in .env prüfen.'], 503);
        }
        $email = mb_substr(trim((string) $request->postValue('email', '')), 0, 254);
        $passwort = (string) $request->postValue('passwort', '');
        $provisioning = new AdminProvisioning($pdo, $this->config);
        try {
            if ($provisioning->count() > 0) {
                return $this->uebersicht(['fehler' => 'Es existiert bereits ein Admin-Konto. Weitere Konten legt ein Admin an bzw. bin/admin-user.php.'], 409);
            }
            if (!hash_equals($passwort, (string) $request->postValue('passwort_wiederholung', ''))) {
                return $this->uebersicht(['fehler' => 'Die Passwörter stimmen nicht überein.', 'admin_email' => $email], 422);
            }
            $fehler = $provisioning->validate($email, $passwort);
            if ($fehler !== []) {
                return $this->uebersicht(['fehler' => implode(' ', $fehler), 'admin_email' => $email], 422);
            }
            $konto = $provisioning->create($email, $passwort);
        } catch (Throwable $e) {
            $this->log->error('Einrichtung: Admin-Anlage fehlgeschlagen', ['fehler' => get_class($e)]);

            return $this->uebersicht(['fehler' => 'Admin-Konto konnte nicht angelegt werden (' . get_class($e) . '). Sind die Migrationen ausgeführt?'], 500);
        }
        $this->log->info('Einrichtung: Admin-Benutzer angelegt', ['admin_user_id' => $konto['id']]);

        return $this->uebersicht([
            'meldung' => 'Admin-Konto angelegt. Geheimnis und Wiederherstellungscodes jetzt sichern, sie werden nicht erneut angezeigt.',
            'neues_konto' => [
                'email' => $konto['email'],
                'geheimnis' => trim(chunk_split($konto['secret'], 4, ' ')),
                'uri' => $konto['uri'],
                'codes' => $konto['codes'],
            ],
        ]);
    }

    private function hashErzeugen(Request $request): Response
    {
        $benutzer = trim((string) $request->postValue('benutzer', ''));
        $passwort = (string) $request->postValue('passwort', '');
        if ($benutzer === '' || !preg_match('/^[A-Za-z0-9._-]{3,64}$/', $benutzer)) {
            return $this->uebersicht(['fehler' => 'Benutzername: 3 bis 64 Zeichen, nur Buchstaben, Ziffern, Punkt, Unterstrich und Bindestrich.'], 422);
        }
        if (mb_strlen($passwort) < 12) {
            return $this->uebersicht(['fehler' => 'Das Passwort für den Staging-Schutz muss mindestens 12 Zeichen lang sein.', 'hash_benutzer' => $benutzer], 422);
        }

        return $this->uebersicht([
            'meldung' => 'Hash erzeugt. Zeile in .env eintragen, das Passwort selbst wird nicht gespeichert.',
            'hash_zeile' => "STAGING_BASIC_AUTH='" . $benutzer . ':' . password_hash($passwort, PASSWORD_DEFAULT) . "'",
        ]);
    }

    private function abschliessen(Request $request): Response
    {
        if ($request->postValue('bestaetigung') !== 'ja') {
            return $this->uebersicht(['fehler' => $this->dateiModus()
                ? 'Bitte bestätigen, dass die Diagnose geprüft und das Webhook-Geheimnis in n8n hinterlegt ist.'
                : 'Bitte bestätigen, dass Admin-Zugang und Wiederherstellungscodes gesichert sind.'], 422);
        }
        $datei = $this->storage() . '/' . self::LOCK_DATEI;
        $inhalt = 'Einrichtung abgeschlossen am ' . gmdate('Y-m-d H:i:s') . " UTC\n";
        if (@file_put_contents($datei, $inhalt, LOCK_EX) === false) {
            return $this->uebersicht(['fehler' => 'storage/setup.lock konnte nicht geschrieben werden. Schreibrechte für storage prüfen.'], 500);
        }
        $this->session->remove(self::SESSION_KEY);
        $this->session->regenerate();
        $this->log->info('Einrichtung abgeschlossen');

        return self::sicher($this->render('einrichtung/abgeschlossen.html.twig', ['titel' => 'Einrichtung abgeschlossen', 'datei_modus' => $this->dateiModus()]));
    }

    private function anmeldung(?string $fehler, int $status = 200): Response
    {
        return self::sicher($this->render('einrichtung/anmeldung.html.twig', [
            'titel' => 'Einrichtung',
            'fehler' => $fehler,
        ], $status));
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function uebersicht(array $vars, int $status = 200): Response
    {
        $basis = rtrim((string) $this->config->get('app.base_path'), '/');
        $diagnose = (new Diagnose($basis, static fn (string $k): ?string => Env::get($k)))->run();

        $offen = null;
        $adminAnzahl = null;
        $pdo = $this->pdo();
        if ($pdo !== null) {
            try {
                $offen = (new Migrator($pdo, $basis . '/migrations'))->pending();
                if ($offen === []) {
                    $adminAnzahl = (new AdminProvisioning($pdo, $this->config))->count();
                }
            } catch (Throwable $e) {
                $offen = null;
            }
        }

        return self::sicher($this->render('einrichtung/uebersicht.html.twig', $vars + [
            'titel' => 'Einrichtung',
            'diagnose' => $diagnose->ergebnisse(),
            'diagnose_fehler' => $diagnose->fehlerAnzahl(),
            'datei_modus' => $this->dateiModus(),
            'datenbank' => $pdo !== null,
            'migrationen_offen' => $offen,
            'admin_anzahl' => $adminAnzahl,
            'app_key' => SpamGuard::hasAppKey($this->config),
            'fehler' => null,
            'meldung' => null,
            'neues_konto' => null,
            'hash_zeile' => null,
            'admin_email' => '',
            'hash_benutzer' => '',
            'migration_zeilen' => [],
        ], $status));
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(string $template, array $vars, int $status = 200): Response
    {
        // Layout layouts/admin.html.twig erwartet admin.user und admin.flash (strict_variables)
        return $this->view->response($template, $vars + ['admin' => ['user' => null, 'flash' => null, 'bereich' => 'einrichtung']], $status);
    }

    public static function sicher(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
