<?php

declare(strict_types=1);

/*
 * Diagnose bei HTTP 500 nach dem Deployment. Nur auf der Kommandozeile ausführen:
 *   php bin/diagnose.php
 * Gibt keine Geheimnisse aus, nur ob Werte gesetzt und gültig sind.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$basis = dirname(__DIR__);
$fehler = 0;

$pruefe = static function (bool $ok, string $text, string $hinweis = '') use (&$fehler): void {
    echo ($ok ? '[OK]     ' : '[FEHLER] ') . $text . ($ok || $hinweis === '' ? '' : "\n         Lösung: " . $hinweis) . "\n";
    if (!$ok) {
        $fehler++;
    }
};

echo "Diagnose Hausverwaltung Müller Webseite\n\n";

$pruefe(PHP_VERSION_ID >= 80300, 'PHP-Version ' . PHP_VERSION . ' (mindestens 8.3)', 'PHP 8.3 oder neuer im Hosting bzw. Container einstellen.');
foreach (['pdo_mysql', 'mbstring', 'intl', 'sodium', 'gd', 'json'] as $ext) {
    $pruefe(extension_loaded($ext), "PHP-Erweiterung $ext", "Erweiterung $ext aktivieren.");
}

$autoload = $basis . '/vendor/autoload.php';
$pruefe(is_file($autoload), 'vendor/autoload.php vorhanden', 'Im Projektordner ausführen: composer install --no-dev --optimize-autoloader');

$envDatei = $basis . '/.env';
$pruefe(is_file($envDatei), '.env vorhanden', '.env.example nach .env kopieren und ausfüllen (APP_ENV, APP_URL, APP_KEY, DB_*).');

$env = [];
if (is_file($envDatei)) {
    foreach (file($envDatei, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $zeile) {
        $zeile = trim($zeile);
        if ($zeile === '' || $zeile[0] === '#' || !str_contains($zeile, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $zeile, 2);
        $env[trim($k)] = trim(trim($v), "\"'");
    }
}
$wert = static fn (string $k): string => (string) (getenv($k) !== false ? getenv($k) : ($env[$k] ?? ''));

$appEnv = $wert('APP_ENV');
$pruefe(in_array($appEnv, ['production', 'staging', 'development'], true), 'APP_ENV gesetzt (' . ($appEnv === '' ? 'leer, gilt als production' : $appEnv) . ')', 'Für neu.muellerhv.de APP_ENV=staging setzen.');

$key = $wert('APP_KEY');
$roh = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : false;
$pruefe($roh !== false && strlen($roh) >= 32, 'APP_KEY gültig (base64:, mindestens 32 Byte)', 'Schlüssel erzeugen: php -r \'echo "base64:".base64_encode(random_bytes(32)), PHP_EOL;\' und als APP_KEY eintragen. Ohne gültigen Schlüssel startet die Seite in Produktion bewusst nicht.');

$pruefe($wert('APP_URL') !== '', 'APP_URL gesetzt (' . ($wert('APP_URL') ?: 'leer') . ')', 'APP_URL=https://neu.muellerhv.de setzen.');

foreach (['storage', 'storage/logs', 'storage/cache', 'storage/cache/twig'] as $ordner) {
    $pfad = $basis . '/' . $ordner;
    if (!is_dir($pfad)) {
        @mkdir($pfad, 0775, true);
    }
    $pruefe(is_dir($pfad) && is_writable($pfad), "$ordner beschreibbar", "Ordner anlegen und für den Webserver-Benutzer beschreibbar machen, z. B.: chown -R www-data:www-data storage && chmod -R 775 storage");
}

$manifest = $basis . '/public/assets/build/manifest.json';
$pruefe(is_file($manifest), 'Asset-Build vorhanden (public/assets/build/manifest.json)', 'Build ausführen: composer build (bzw. php bin/build-assets.php, bin/build-og-images.php, bin/build-search-index.php).');

if ($wert('DB_HOST') !== '') {
    try {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $wert('DB_HOST'), $wert('DB_PORT') ?: '3306', $wert('DB_NAME'));
        new PDO($dsn, $wert('DB_USER'), $wert('DB_PASSWORD'), [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pruefe(true, 'Datenbankverbindung');
    } catch (Throwable $e) {
        $pruefe(false, 'Datenbankverbindung (' . get_class($e) . ')', 'DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD prüfen, danach php bin/migrate.php ausführen. Inhaltsseiten laufen auch ohne Datenbank, Formulare nicht.');
    }
} else {
    $pruefe(false, 'DB_HOST gesetzt', 'Datenbankzugang in .env eintragen.');
}

echo "\nProbeaufruf der Startseite:\n";
if (is_file($autoload)) {
    require $autoload;
    try {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_HOST'] = parse_url($wert('APP_URL') ?: 'http://localhost', PHP_URL_HOST) ?: 'localhost';
        $antwort = \Hvm\Http\Kernel::fromGlobals()->handle();
        $status = $antwort->status();
        $pruefe($status === 200, "Startseite liefert Status $status", 'Details stehen in storage/logs/app.log (letzte Zeilen: tail -n 50 storage/logs/app.log).');
    } catch (Throwable $e) {
        $pruefe(false, 'Start der Anwendung: ' . get_class($e) . ': ' . $e->getMessage(), 'Meldung oben beheben. Weitere Details in storage/logs/app.log.');
    }
}

echo "\n" . ($fehler === 0 ? 'Keine Fehler gefunden.' : "$fehler Problem(e) gefunden.") . "\n";
exit($fehler === 0 ? 0 : 1);
