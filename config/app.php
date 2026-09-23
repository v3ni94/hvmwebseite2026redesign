<?php

declare(strict_types=1);

use Hvm\Support\Env;

$env = Env::string('APP_ENV', 'production');
if (!in_array($env, ['development', 'staging', 'production'], true)) {
    $env = 'production';
}

return [
    // development | staging | production. Unbekannte oder fehlende Werte gelten als production (sichere Voreinstellung).
    'env' => $env,
    'url' => rtrim(Env::string('APP_URL', 'https://www.muellerhv.de'), '/'),
    'key' => Env::get('APP_KEY'),
    'show_drafts' => Env::bool('SHOW_DRAFTS', false),
    'price_indication' => Env::bool('PRICE_INDICATION_ENABLED', false),
    'timezone' => 'Europe/Berlin',
    'locale' => 'de_DE',

    'db' => [
        'host' => Env::string('DB_HOST', '127.0.0.1'),
        'port' => Env::int('DB_PORT', 3306),
        'socket' => Env::get('DB_SOCKET'),
        'name' => Env::get('DB_NAME'),
        'user' => Env::get('DB_USER'),
        'password' => Env::get('DB_PASSWORD'),
    ],
    'db_test' => [
        'host' => Env::string('DB_TEST_HOST', Env::string('DB_HOST', '127.0.0.1')),
        'port' => Env::int('DB_TEST_PORT', Env::int('DB_PORT', 3306)),
        'socket' => Env::get('DB_TEST_SOCKET', Env::get('DB_SOCKET')),
        'name' => Env::get('DB_TEST_NAME'),
        'user' => Env::get('DB_TEST_USER', Env::get('DB_USER')),
        'password' => Env::get('DB_TEST_PASSWORD', Env::get('DB_PASSWORD')),
    ],

    'mail' => [
        'host' => Env::get('MAIL_HOST'),
        'port' => Env::int('MAIL_PORT', 587),
        'user' => Env::get('MAIL_USER'),
        'password' => Env::get('MAIL_PASSWORD'),
        'encryption' => Env::string('MAIL_ENCRYPTION', 'tls'),
        'from' => Env::get('MAIL_FROM'),
        'from_name' => Env::string('MAIL_FROM_NAME', 'Hausverwaltung Müller GmbH'),
    ],
    // [Empfängeradresse Leads festlegen]
    'lead_notify_to' => Env::get('LEAD_NOTIFY_TO'),
    'n8n' => [
        'webhook_url' => Env::get('N8N_WEBHOOK_URL'),
        'webhook_secret' => Env::get('N8N_WEBHOOK_SECRET'),
        // Nur Produktion: http an interne Hosts (Docker-Dienstname ohne Punkt, private IP) erlauben, sonst ausschließlich https
        'allow_http_internal' => Env::bool('N8N_WEBHOOK_ALLOW_HTTP_INTERNAL', false),
    ],

    'trusted_proxies' => Env::list('TRUSTED_PROXIES'),
    'admin_ip_allowlist' => Env::list('ADMIN_IP_ALLOWLIST'),
    // [Aufbewahrungsfrist festlegen], null = Löschlauf deaktiviert
    'lead_retention_days' => Env::int('LEAD_RETENTION_DAYS'),

    'session' => [
        'name' => 'hvm_sid',
        'secure' => $env !== 'development',
        'idle_timeout' => Env::int('SESSION_IDLE_TIMEOUT', 1800),
        // native (PHP-Sitzung) oder array (nur Speicher, für Tests)
        'driver' => Env::string('SESSION_DRIVER', 'native'),
        // Pfadpräfixe, auf denen die Sitzung immer startet (Formulare, Admin)
        'paths' => ['/angebot/', '/kontakt/', '/karriere/bewerbung/', '/admin/'],
    ],
    // Pfadpräfixe ohne CSRF-Prüfung, nur für signierte Maschinenschnittstellen
    'csrf_exempt' => [],

    // Klassen, die Hvm\Support\SitemapProvider implementieren (z. B. Wissensartikel)
    'sitemap_providers' => [\Hvm\Content\WissenSitemapProvider::class],
];
