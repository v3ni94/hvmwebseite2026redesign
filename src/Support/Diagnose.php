<?php

declare(strict_types=1);

namespace Hvm\Support;

use PDO;
use Throwable;

/**
 * Prüflogik für bin/diagnose.php (Kommandozeile) und die Web-Einrichtung /_einrichtung/ (Browser).
 *
 * Gibt nie Geheimnisse aus: nur ob Werte gesetzt und gültig sind. Bei Datenbankfehlern erscheint nur die
 * Fehlerklasse, nicht die Meldung (sie kann Benutzer- oder Hostnamen enthalten).
 *
 * Die Klasse hängt nur von Hvm\Support\Env (Parser) ab, damit bin/diagnose.php sie auch ohne vendor/ laden kann.
 */
final class Diagnose
{
    public const OK = 'ok';
    public const FEHLER = 'fehler';
    public const HINWEIS = 'hinweis';

    /**
     * Zur Laufzeit benötigt: Datenbank, Zeichenketten, Verschlüsselung, Sitemap (xmlwriter),
     * Uploads (fileinfo), SMTP mit TLS (openssl), PHPMailer (ctype)
     */
    private const ERWEITERUNGEN = ['pdo_mysql', 'mbstring', 'sodium', 'json', 'ctype', 'xmlwriter', 'fileinfo', 'openssl'];
    /** Optional: curl für den n8n-Webhook, gd nur für den Build (im Release-Paket bereits erledigt) */
    private const ERWEITERUNGEN_OPTIONAL = ['curl' => 'curl wird nur für den n8n-Webhook benötigt.', 'gd' => 'gd wird nur für den Build benötigt, der im Release-Paket bereits erledigt ist.'];

    public const SETUP_TOKEN_MIN = 32;

    /** @var list<array{status: string, text: string, loesung: string}> */
    private array $ergebnisse = [];

    /** @var array<string, string> */
    private array $dateiWerte = [];

    /** @var (callable(string): ?string)|null */
    private $quelle;

    /**
     * @param (callable(string): ?string)|null $quelle Werte aus der laufenden Anwendung (Web), sonst .env und Umgebung
     */
    public function __construct(private readonly string $basis, ?callable $quelle = null)
    {
        $this->quelle = $quelle;
        if ($quelle !== null) {
            return;
        }
        $datei = $basis . '/.env';
        if (is_file($datei) && is_readable($datei)) {
            $this->dateiWerte = Env::parse((string) file_get_contents($datei));
        }
    }

    public function wert(string $key): string
    {
        if ($this->quelle !== null) {
            return (string) ($this->quelle)($key);
        }
        $real = getenv($key);
        if ($real !== false) {
            return $real;
        }

        return $this->dateiWerte[$key] ?? '';
    }

    /**
     * @return list<array{status: string, text: string, loesung: string}>
     */
    public function ergebnisse(): array
    {
        return $this->ergebnisse;
    }

    public function fehlerAnzahl(): int
    {
        return count(array_filter($this->ergebnisse, static fn (array $e): bool => $e['status'] === self::FEHLER));
    }

    private function pruefe(bool $ok, string $text, string $loesung = '', bool $nurHinweis = false): bool
    {
        $this->ergebnisse[] = [
            'status' => $ok ? self::OK : ($nurHinweis ? self::HINWEIS : self::FEHLER),
            'text' => $text,
            'loesung' => $ok ? '' : $loesung,
        ];

        return $ok;
    }

    /**
     * Führt alle Prüfungen ohne Probeaufruf der Startseite aus.
     */
    public function run(): self
    {
        $this->ergebnisse = [];
        $this->pruefePhp();
        $this->pruefeDateien();
        $this->pruefeKonfiguration();
        $this->pruefeSpeicher();
        $this->pruefeDatenbank();

        return $this;
    }

    private function pruefePhp(): void
    {
        $this->pruefe(PHP_VERSION_ID >= 80300, 'PHP-Version ' . PHP_VERSION . ' (mindestens 8.3)', 'PHP 8.3 oder neuer im Hosting-Panel für diese Domain bzw. im Container einstellen.');
        foreach (self::ERWEITERUNGEN as $ext) {
            $this->pruefe(extension_loaded($ext), "PHP-Erweiterung $ext", "Erweiterung $ext im Hosting aktivieren.");
        }
        foreach (self::ERWEITERUNGEN_OPTIONAL as $ext => $grund) {
            $this->pruefe(extension_loaded($ext), "PHP-Erweiterung $ext (optional)", $grund, true);
        }
    }

    private function pruefeDateien(): void
    {
        $this->pruefe(is_file($this->basis . '/vendor/autoload.php'), 'vendor/autoload.php vorhanden', 'Release-Paket vollständig hochladen (Ordner vendor) bzw. composer install --no-dev --optimize-autoloader ausführen.');
        $this->pruefe(is_file($this->basis . '/.env') || getenv('APP_KEY') !== false, '.env vorhanden', '.env aus dem Release-Paket hochladen bzw. .env.example nach .env kopieren und ausfüllen. Die Datei ist versteckt, im SFTP-Programm versteckte Dateien anzeigen.');
        $this->pruefe(is_file($this->basis . '/public/assets/build/manifest.json'), 'Asset-Build vorhanden (public/assets/build/manifest.json)', 'Release-Paket vollständig hochladen bzw. composer build ausführen.');
        $this->pruefe(is_file($this->basis . '/public/.htaccess'), 'public/.htaccess vorhanden (Apache)', 'Unter Apache wird public/.htaccess für schöne URLs benötigt. Versteckte Dateien mit hochladen. Unter Nginx ohne Bedeutung.', true);
    }

    private function pruefeKonfiguration(): void
    {
        $appEnv = $this->wert('APP_ENV');
        $this->pruefe(
            in_array($appEnv, ['production', 'staging', 'development'], true),
            'APP_ENV gesetzt (' . ($appEnv === '' ? 'leer, gilt als production' : $appEnv) . ')',
            'Für neu.muellerhv.de APP_ENV=staging setzen.'
        );

        $key = $this->wert('APP_KEY');
        $roh = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : false;
        $this->pruefe(
            $roh !== false && strlen($roh) >= 32,
            'APP_KEY gültig (base64:, mindestens 32 Byte)',
            'Das Release-Paket enthält einen frisch erzeugten Schlüssel. Sonst: php -r \'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;\' und als APP_KEY eintragen. Ohne gültigen Schlüssel startet die Seite in Produktion bewusst nicht.'
        );

        $url = $this->wert('APP_URL');
        $this->pruefe($url !== '', 'APP_URL gesetzt (' . ($url === '' ? 'leer' : $url) . ')', 'APP_URL=https://neu.muellerhv.de setzen.');

        $modus = $this->wert('OUTBOX_MODE') === '' ? 'worker' : $this->wert('OUTBOX_MODE');
        $this->pruefe(in_array($modus, ['inline', 'worker'], true), 'OUTBOX_MODE (' . $modus . ')', 'OUTBOX_MODE=inline (ohne Cronjob) oder worker (Dienst bzw. Cronjob mit bin/worker.php) setzen.');

        $mailFehlt = array_values(array_filter(['MAIL_HOST', 'MAIL_FROM', 'LEAD_NOTIFY_TO'], fn (string $k): bool => $this->wert($k) === ''));
        $this->pruefe(
            $mailFehlt === [],
            'Mailversand konfiguriert' . ($mailFehlt === [] ? '' : ' (fehlt: ' . implode(', ', $mailFehlt) . ')'),
            'SMTP-Zugang (MAIL_*) und LEAD_NOTIFY_TO in .env eintragen. Bis dahin bleiben Benachrichtigungen in der Warteschlange.',
            true
        );

        $basic = $this->wert('STAGING_BASIC_AUTH');
        if ($basic !== '') {
            $hash = str_contains($basic, ':') ? explode(':', $basic, 2)[1] : '';
            $unterstuetzt = $hash !== '' && (password_get_info($hash)['algo'] ?? null) !== null;
            $this->pruefe(
                $unterstuetzt,
                'STAGING_BASIC_AUTH ' . ($unterstuetzt ? 'gesetzt (' . (string) password_get_info($hash)['algoName'] . ')' : 'mit nicht unterstütztem Format'),
                'Format benutzer:hash mit einem bcrypt- oder Argon2-Hash (Hash-Hilfe der Einrichtungsseite). apr1-Hashes (htpasswd) prüft nur ein vorgeschalteter Proxy, die Anwendung ignoriert sie.',
                true
            );
        }

        $token = $this->wert('SETUP_TOKEN');
        if ($token !== '') {
            $lock = is_file($this->basis . '/storage/setup.lock');
            $this->pruefe(
                strlen($token) >= self::SETUP_TOKEN_MIN && !$lock,
                'SETUP_TOKEN ' . ($lock ? 'gesetzt, Einrichtung abgeschlossen (storage/setup.lock)' : (strlen($token) >= self::SETUP_TOKEN_MIN ? 'gesetzt, Einrichtung aktiv' : 'zu kurz, Einrichtung gesperrt')),
                $lock ? 'SETUP_TOKEN kann aus .env entfernt werden.' : 'Mindestens ' . self::SETUP_TOKEN_MIN . ' zufällige Zeichen verwenden.',
                true
            );
        }
    }

    private function pruefeSpeicher(): void
    {
        foreach (['storage', 'storage/logs', 'storage/cache', 'storage/cache/twig', 'storage/uploads', 'storage/ratelimit'] as $ordner) {
            $pfad = $this->basis . '/' . $ordner;
            if (!is_dir($pfad)) {
                @mkdir($pfad, 0775, true);
            }
            $this->pruefe(
                is_dir($pfad) && is_writable($pfad),
                "$ordner beschreibbar",
                'Ordner anlegen und für den Webserver beschreibbar machen: im SFTP-Programm Rechte 755 (bzw. 775) setzen, auf eigenen Servern z. B. chown -R www-data:www-data storage && chmod -R 775 storage.'
            );
        }
    }

    private function pruefeDatenbank(): void
    {
        if ($this->wert('DB_HOST') === '' && $this->wert('DB_SOCKET') === '') {
            $this->pruefe(false, 'DB_HOST gesetzt', 'Datenbankzugang (DB_HOST, DB_NAME, DB_USER, DB_PASSWORD) aus dem Hosting-Panel in .env eintragen. Inhaltsseiten laufen auch ohne Datenbank, Formulare und Admin nicht.');

            return;
        }
        $pdo = $this->verbindung();
        if ($pdo === null) {
            return;
        }
        try {
            $migrator = new Migrator($pdo, $this->basis . '/migrations');
            $offen = $migrator->pending();
            $this->pruefe($offen === [], 'Migrationen ' . ($offen === [] ? 'vollständig (' . count($migrator->applied()) . ')' : count($offen) . ' offen'), 'Migrationen über die Einrichtungsseite bzw. php bin/migrate.php ausführen.');
            if ($offen === []) {
                $anzahl = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
                $this->pruefe($anzahl > 0, 'Admin-Konten: ' . $anzahl, 'Ersten Admin über die Einrichtungsseite bzw. php bin/admin-user.php create anlegen.', true);
            }
        } catch (Throwable $e) {
            $this->pruefe(false, 'Migrationsstand lesbar (' . get_class($e) . ')', 'Rechte des Datenbankbenutzers prüfen (CREATE, ALTER, INDEX, SELECT, INSERT, UPDATE, DELETE).');
        }
    }

    public function verbindung(): ?PDO
    {
        try {
            $socket = $this->wert('DB_SOCKET');
            $dsn = $socket !== ''
                ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $this->wert('DB_NAME'))
                : sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $this->wert('DB_HOST'), $this->wert('DB_PORT') ?: '3306', $this->wert('DB_NAME'));
            $pdo = new PDO($dsn, $this->wert('DB_USER'), $this->wert('DB_PASSWORD'), [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->pruefe(true, 'Datenbankverbindung');

            return $pdo;
        } catch (Throwable $e) {
            $this->pruefe(false, 'Datenbankverbindung (' . get_class($e) . ')', 'DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD prüfen (Werte aus dem Hosting-Panel). Inhaltsseiten laufen auch ohne Datenbank, Formulare nicht.');

            return null;
        }
    }

    /**
     * Probeaufruf der Startseite über einen eigenen Kernel (nur Kommandozeile, setzt vendor/ voraus).
     */
    public function probeStartseite(): void
    {
        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/';
            $_SERVER['HTTP_HOST'] = parse_url($this->wert('APP_URL') ?: 'http://localhost', PHP_URL_HOST) ?: 'localhost';
            $status = \Hvm\Http\Kernel::fromGlobals($this->basis)->handle()->status();
            $this->pruefe($status === 200, "Startseite liefert Status $status", 'Details stehen in storage/logs/app.log.');
        } catch (Throwable $e) {
            $this->pruefe(false, 'Start der Anwendung: ' . get_class($e) . ': ' . $e->getMessage(), 'Meldung oben beheben. Weitere Details in storage/logs/app.log.');
        }
    }
}
