<?php

declare(strict_types=1);

/*
 * Verwaltung der Admin-Benutzer (MP 6.5).
 *
 * Aufruf:
 *   php bin/admin-user.php create <email>          Passwort interaktiv (verdeckt) oder per STDIN (erste Zeile),
 *                                                  erzeugt ein TOTP-Geheimnis und zehn Wiederherstellungscodes und gibt
 *                                                  otpauth-URI, Geheimnis und Codes einmalig aus
 *   php bin/admin-user.php reset-totp <email>      neues TOTP-Geheimnis, einmalige Ausgabe (Wiederherstellungscodes bleiben gültig)
 *   php bin/admin-user.php recovery-codes <email>  zehn neue Wiederherstellungscodes, alle bisherigen werden ungültig
 *   php bin/admin-user.php set-password <email>    neues Passwort (interaktiv oder per STDIN)
 *   php bin/admin-user.php disable <email>         Konto deaktivieren (Anmeldung und laufende Sitzungen enden)
 *   php bin/admin-user.php enable <email>          Konto wieder aktivieren
 *   php bin/admin-user.php list                    Konten ohne Geheimnisse anzeigen
 *
 * Voraussetzung: APP_KEY ist gesetzt (Verschlüsselung des TOTP-Geheimnisses) und die Migrationen sind ausgeführt.
 * Geheimnis und Wiederherstellungscodes werden nur im Terminal angezeigt, nie protokolliert. Die Codes sind
 * nur als HMAC gespeichert (Hvm\Security\RecoveryCodes) und lassen sich später nicht erneut anzeigen.
 */

use Hvm\Http\Kernel;
use Hvm\Security\AdminAuth;
use Hvm\Security\AdminProvisioning;
use Hvm\Security\Password;
use Hvm\Security\RecoveryCodes;
use Hvm\Security\SpamGuard;
use Hvm\Security\Totp;
use Hvm\Support\Clock;
use Hvm\Support\Config;
use Hvm\Support\Log;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

const ISSUER = AdminProvisioning::ISSUER;

$command = $argv[1] ?? '';
$email = AdminAuth::normalizeEmail((string) ($argv[2] ?? ''));

$fail = static function (string $message, int $code = 1): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
};

if (!in_array($command, ['create', 'reset-totp', 'recovery-codes', 'set-password', 'disable', 'enable', 'list'], true)) {
    $fail("Aufruf: php bin/admin-user.php create|reset-totp|recovery-codes|set-password|disable|enable <email>\n       php bin/admin-user.php list", 2);
}
if ($command !== 'list' && ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254)) {
    $fail('Bitte eine gültige E-Mail-Adresse angeben.', 2);
}

$kernel = Kernel::fromGlobals($root);
$container = $kernel->container();
/** @var Config $config */
$config = $container->get(Config::class);
/** @var Log $log */
$log = $container->get(Log::class);
try {
    /** @var PDO $pdo */
    $pdo = $container->get(PDO::class);
} catch (Throwable $e) {
    $fail('Datenbank nicht erreichbar: ' . get_class($e));
}

$isTty = function_exists('posix_isatty') ? posix_isatty(STDIN) : (function_exists('stream_isatty') && stream_isatty(STDIN));

$readPassword = static function () use ($isTty, $fail, $email): string {
    if (!$isTty) {
        $line = fgets(STDIN);
        $password = $line === false ? '' : rtrim($line, "\r\n");
    } else {
        $prompt = static function (string $label): string {
            fwrite(STDOUT, $label);
            @shell_exec('stty -echo 2>/dev/null');
            $line = fgets(STDIN);
            @shell_exec('stty echo 2>/dev/null');
            fwrite(STDOUT, PHP_EOL);

            return $line === false ? '' : rtrim($line, "\r\n");
        };
        $password = $prompt('Passwort: ');
        if ($prompt('Passwort wiederholen: ') !== $password) {
            $fail('Die Passwörter stimmen nicht überein.');
        }
    }
    $errors = Password::policyErrors($password, $email);
    if ($errors !== []) {
        $fail(implode(PHP_EOL, $errors));
    }

    return $password;
};

$findUser = static function (string $email) use ($pdo): ?array {
    $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
};

$printSecret = static function (string $secret, string $email): void {
    fwrite(STDOUT, PHP_EOL . 'TOTP-Geheimnis (nur jetzt sichtbar, in der Authenticator-App hinterlegen):' . PHP_EOL);
    fwrite(STDOUT, '  Geheimnis: ' . trim(chunk_split($secret, 4, ' ')) . PHP_EOL);
    fwrite(STDOUT, '  otpauth-URI: ' . Totp::uri($secret, $email, ISSUER) . PHP_EOL);
    fwrite(STDOUT, 'Die URI kann lokal in einen QR-Code umgewandelt werden. Nicht per E-Mail oder Chat weitergeben.' . PHP_EOL);
};

$printRecoveryCodes = static function (array $codes): void {
    fwrite(STDOUT, PHP_EOL . 'Wiederherstellungscodes (je einmal verwendbar, nur jetzt sichtbar, offline sicher verwahren):' . PHP_EOL);
    foreach ($codes as $i => $code) {
        fwrite(STDOUT, sprintf('  %2d. %s', $i + 1, $code) . PHP_EOL);
    }
    fwrite(STDOUT, 'Ein Code ersetzt bei der Anmeldung den Code aus der Authenticator-App.' . PHP_EOL);
};

$now = Clock::now()->format('Y-m-d H:i:s');

switch ($command) {
    case 'create':
        if (!SpamGuard::hasAppKey($config)) {
            $fail('APP_KEY fehlt. Ohne APP_KEY kann das TOTP-Geheimnis nicht verschlüsselt werden.');
        }
        if ($findUser($email) !== null) {
            $fail('Ein Konto mit dieser E-Mail-Adresse existiert bereits.');
        }
        $password = $readPassword();
        $konto = (new AdminProvisioning($pdo, $config))->create($email, $password);
        $log->info('Admin-Benutzer angelegt', ['admin_user_id' => $konto['id']]);
        fwrite(STDOUT, sprintf('Konto %d angelegt.', $konto['id']) . PHP_EOL);
        $printSecret($konto['secret'], $email);
        $printRecoveryCodes($konto['codes']);
        break;

    case 'recovery-codes':
        if (!SpamGuard::hasAppKey($config)) {
            $fail('APP_KEY fehlt. Ohne APP_KEY können keine Wiederherstellungscodes erzeugt werden.');
        }
        $user = $findUser($email) ?? $fail('Konto nicht gefunden.');
        $codes = (new RecoveryCodes($pdo, $config))->regenerate((int) $user['id']);
        $log->info('Admin-Benutzer: Wiederherstellungscodes neu erzeugt', ['admin_user_id' => (int) $user['id'], 'anzahl' => count($codes)]);
        fwrite(STDOUT, 'Neue Wiederherstellungscodes erzeugt. Alle bisherigen Codes sind ungültig.' . PHP_EOL);
        $printRecoveryCodes($codes);
        break;

    case 'reset-totp':
        if (!SpamGuard::hasAppKey($config)) {
            $fail('APP_KEY fehlt. Ohne APP_KEY kann das TOTP-Geheimnis nicht verschlüsselt werden.');
        }
        $user = $findUser($email) ?? $fail('Konto nicht gefunden.');
        $secret = Totp::generateSecret();
        $pdo->prepare('UPDATE admin_users SET totp_secret = ?, totp_enabled = 1, totp_last_step = NULL WHERE id = ?')
            ->execute([AdminAuth::encryptSecret($config, $secret), (int) $user['id']]);
        $log->info('Admin-Benutzer: TOTP neu erzeugt', ['admin_user_id' => (int) $user['id']]);
        fwrite(STDOUT, 'TOTP-Geheimnis ersetzt. Das bisherige Geheimnis ist ungültig.' . PHP_EOL);
        $printSecret($secret, $email);
        break;

    case 'set-password':
        $user = $findUser($email) ?? $fail('Konto nicht gefunden.');
        $password = $readPassword();
        $pdo->prepare('UPDATE admin_users SET password_hash = ?, password_changed_at = ?, failed_logins = 0, locked_until = NULL WHERE id = ?')
            ->execute([Password::hash($password), $now, (int) $user['id']]);
        $log->info('Admin-Benutzer: Passwort geändert', ['admin_user_id' => (int) $user['id']]);
        fwrite(STDOUT, 'Passwort geändert.' . PHP_EOL);
        break;

    case 'disable':
        $user = $findUser($email) ?? $fail('Konto nicht gefunden.');
        $pdo->prepare('UPDATE admin_users SET disabled_at = COALESCE(disabled_at, ?) WHERE id = ?')->execute([$now, (int) $user['id']]);
        $log->info('Admin-Benutzer deaktiviert', ['admin_user_id' => (int) $user['id']]);
        fwrite(STDOUT, 'Konto deaktiviert. Laufende Sitzungen enden mit der nächsten Anfrage.' . PHP_EOL);
        break;

    case 'enable':
        $user = $findUser($email) ?? $fail('Konto nicht gefunden.');
        $pdo->prepare('UPDATE admin_users SET disabled_at = NULL, failed_logins = 0, locked_until = NULL WHERE id = ?')->execute([(int) $user['id']]);
        $log->info('Admin-Benutzer aktiviert', ['admin_user_id' => (int) $user['id']]);
        fwrite(STDOUT, 'Konto aktiviert.' . PHP_EOL);
        break;

    case 'list':
        $rows = $pdo->query(
            'SELECT u.id, u.email, u.totp_enabled, u.locked_until, u.disabled_at, u.last_login_at, u.created_at,
                    (SELECT COUNT(*) FROM admin_recovery_codes r WHERE r.admin_user_id = u.id AND r.used_at IS NULL) AS codes
             FROM admin_users u ORDER BY u.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            fwrite(STDOUT, 'Keine Admin-Benutzer vorhanden.' . PHP_EOL);
            break;
        }
        $fmt = static function (?string $utc): string {
            if ($utc === null) {
                return '';
            }

            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i');
        };
        fwrite(STDOUT, sprintf("%-4s %-40s %-5s %-6s %-11s %-16s %-16s\n", 'ID', 'E-Mail', 'TOTP', 'Codes', 'Status', 'Letzte Anmeldung', 'Angelegt'));
        foreach ($rows as $row) {
            $status = $row['disabled_at'] !== null ? 'deaktiviert' : ($row['locked_until'] !== null && $row['locked_until'] > $now ? 'gesperrt' : 'aktiv');
            fwrite(STDOUT, sprintf(
                "%-4d %-40s %-5s %-6d %-11s %-16s %-16s\n",
                (int) $row['id'],
                (string) $row['email'],
                (int) $row['totp_enabled'] === 1 ? 'ja' : 'nein',
                (int) $row['codes'],
                $status,
                $fmt($row['last_login_at']),
                $fmt($row['created_at'])
            ));
        }
        break;
}
exit(0);
