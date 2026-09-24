# Architektur und Konventionen (verbindlich für alle Beteiligten)

Stand: 23.09.2026. Grundlage: `docs/quellen/masterprompt-relaunch-muellerhv-de.md` (im Folgenden MP) und `docs/bestandsaufnahme-muellerhv-de.md`.
Dieses Dokument legt fest, wie Code, Templates und Inhalte aufgebaut sind, damit parallel arbeitende Personen und Agenten konsistent bleiben. Abweichungen nur nach Eintrag in `docs/entscheidungen.md`.

## 1. Stack

| Bereich | Festlegung | Begründung |
|---|---|---|
| Sprache | PHP 8.3 (lokal kompatibel mit 8.4), `declare(strict_types=1);` in jeder Datei | MP Abschnitt 4 |
| Webserver | Nginx + PHP-FPM, je ein Container | etabliert, Security Header und Redirects transparent in Nginx konfigurierbar, arbeitet sauber hinter Traefik ohne eigenes TLS |
| Datenbank | MariaDB 10.11 LTS, eigener DB-Benutzer mit minimalen Rechten | MP Abschnitt 4 |
| Worker | gleicher PHP-Container, Befehl `php bin/worker.php` (Outbox: Webhook und Mail mit Retry) | Warteschlange bei Ausfall n8n oder SMTP |
| Templating | `twig/twig` ^3, Auto-Escaping `html`, `strict_variables` außerhalb Produktion | Auto-Escaping, ausgereift, sicherheitsgepflegt |
| Mail | `phpmailer/phpmailer` ^6 | keine Abhängigkeiten, SMTP mit TLS |
| Markdown | `league/commonmark` ^2 mit FrontMatter-Erweiterung, dazu `symfony/yaml` ^7 | Wissensartikel als Markdown mit Frontmatter (MP 7) |
| Routing | eigene Klasse `Hvm\Http\Router` (statische Routen plus einfache Platzhalter) | unter 150 Zeilen, keine Abhängigkeit nötig |
| Validierung | eigene Klasse `Hvm\Validation\Validator` | geringe Komplexität, volle Kontrolle über deutsche Fehlermeldungen |
| TOTP | eigene Implementierung RFC 6238 mit Testvektoren aus dem RFC | wenige Zeilen, testbar, keine Abhängigkeit |
| Tests | `phpunit/phpunit` ^11 (dev), Node nur für Prüfwerkzeuge: `@playwright/test` 1.56.1, `@axe-core/playwright` (dev) | Chromium ist vorinstalliert, Version passend gepinnt |
| CSS | eigene Design-Tokens, Cascade Layers, Container Queries, `:has()`, `clamp()`. Kein Framework | MP 4 |
| JS | Vanilla JS als ES-Module, progressive Enhancement | MP 4 |

Weitere Pakete nur nach Eintrag mit Begründung in `docs/entscheidungen.md`.

## 2. Verzeichnisstruktur

```
/public              index.php (Front Controller), robots.txt wird dynamisch erzeugt
/public/assets/build CSS/JS-Build mit Hash (nicht im Repository, per bin/build-assets.php)
/public/assets/img   Logo-Varianten, Favicons, später freigegebene Bilder (AVIF/WebP)
/public/og           generierte Open-Graph-Bilder (Build, nicht im Repository)
/resources/css       CSS-Quelldateien, nummeriert, werden in dieser Reihenfolge gebündelt
/resources/js        JS-Quelldateien (ES-Module), Einstieg app.js, admin.js, angebot.js
/src                 Namespace Hvm\ (PSR-4)
  /Http              Kernel, Router, Request, Response, Middleware/*
  /Controller        PageController, AngebotController, KontaktController, WissenController, Admin/*
  /Service           LeadService, MailService, WebhookService, OutboxWorker, PriceIndication, SearchIndex
  /Repository        LeadRepository, AdminUserRepository, ...
  /Security          Csrf, RateLimiter, Totp, Password, SpamGuard, Crypto
  /Validation        Validator
  /View              View (Twig-Setup), TwigExtension
  /Content           WissenRepository (Markdown), Frontmatter
  /Support           Config, Env, Db, Clock, Uuid, Log (ohne personenbezogene Daten)
/templates
  /layouts           base.html.twig, admin.html.twig, email.html.twig
  /partials          header, footer, mobile-bar, kennlinie, hausumriss, breadcrumbs, schema, draft-banner
  /components        Twig-Makros (button, eyebrow, section-head, card, bento, kennzahlen, timeline, faq, form-fields, cta-band, placeholder)
  /pages             eine Datei je Seite, z. B. start.html.twig, weg-verwaltung.html.twig
  /admin             Admin-Ansichten
  /emails            Mailtexte (HTML und Text)
/content/wissen      Markdown-Artikel mit Frontmatter
/content/faq         FAQ als YAML je Zielgruppe
/content/staedte     Stadtseiten als Markdown mit Frontmatter (je Stadt aus config/staedte.php)
/config              app.php, unternehmen.php, kennzahlen.php, staedte.php, redirects.php, routes.php, navigation.php, freigaben.php, kundenstimmen.php, seiten.php (Meta je Seite)
/migrations          NNNN_beschreibung.sql, fortlaufend, nie nachträglich ändern
/bin                 migrate.php, deploy-post.sh, worker.php, build-assets.php, build-search-index.php, build-og-images.php, check-pii.php, lint-dashes.php, check-placeholders.php, import-legacy-leads.php, admin-user.php, retention.php, legacy-mirror.sh
/tests               Unit (PHPUnit), Integration (MariaDB-Testdatenbank), e2e (Playwright)
/docker              php/Dockerfile, php/php-production.ini, nginx/default.conf, nginx/security-headers.conf
/docs                Bestandsaufnahme, Architektur, Entscheidungen, offene Punkte, Redirects, Bildinventar, Phasenberichte
/legacy              Mirror der Altseite, steht in .gitignore, niemals committen
/storage             logs, cache/twig, uploads (verschlüsselt), ratelimit; außerhalb des Webroots, in .gitignore
```

## 3. HTTP-Schicht

- Front Controller `public/index.php` lädt `vendor/autoload.php` und ruft `Hvm\Http\Kernel::fromGlobals()->handle()->send()`.
- Routen in `config/routes.php` als Liste: `['GET', '/weg-verwaltung/', [PageController::class, 'show'], ['page' => 'weg-verwaltung']]`. Platzhalter `{slug}` mit Regex `[a-z0-9-]+`.
- Trailing Slash ist Pflicht für HTML-Seiten. Anfragen ohne Slash werden per 301 umgeleitet (Ausnahme: Dateien mit Endung wie `/sitemap.xml`).
- Middleware-Kette in dieser Reihenfolge: ErrorHandler → SecurityHeaders (inkl. CSP-Nonce) → StagingBasicAuth (nur `APP_ENV=staging` mit `STAGING_BASIC_AUTH`, Webhosting ohne Traefik, `docs/deploy-sftp.md`) → LegacyRedirects (`config/redirects.php`) → TrailingSlash → Session (nur für Formulare und Admin) → Csrf (POST) → Router.
- Antworten immer über `Hvm\Http\Response` (Status, Header, Body). Keine direkte Ausgabe mit `echo` außerhalb von `Response::send()`.
- Fehlerseiten: 404 mit Suche und Kern-CTAs (`templates/pages/404.html.twig`), 500 generisch ohne Details. In Produktion niemals Stacktraces, `display_errors=Off` wird bei `APP_ENV=production` im Code erzwungen (`ini_set`) und in `docker/php/php-production.ini` gesetzt.
- Logging nach `storage/logs/app.log` über `Hvm\Support\Log`. Log-Einträge enthalten nie E-Mail, Telefon, Namen oder Anschriften. Leads werden nur mit `uuid` referenziert.

## 4. Sicherheit (MP 10)

- CSP: `default-src 'self'; script-src 'self' 'nonce-{nonce}'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'; upgrade-insecure-requests`. Kein `unsafe-inline`.
- Daraus folgt für Templates: **keine `style="..."`-Attribute, keine `on*`-Handler, keine Inline-Skripte ohne Nonce.** JSON-LD als `<script type="application/ld+json" nonce="{{ csp_nonce() }}">`. Dynamische Werte (Fortschritt, Zähler) setzt JS über `element.style.setProperty()` oder CSS-Klassen. In SVG nur Präsentationsattribute (`stroke`, `fill`), keine `style`-Attribute.
- Weitere Header: HSTS (nur Produktion), `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()`, `Cross-Origin-Opener-Policy: same-origin`, `X-Frame-Options: DENY`. Staging und Development zusätzlich `X-Robots-Tag: noindex, nofollow`.
- Datenbank ausschließlich über PDO mit Prepared Statements, `PDO::ATTR_EMULATE_PREPARES => false`, `utf8mb4`.
- CSRF-Token in allen POST-Formularen (`{{ csrf_field() }}`), Prüfung in Middleware.
- Sessions: `session.use_strict_mode=1`, Cookie `Secure` (außer Development), `HttpOnly`, `SameSite=Lax`, Name `hvm_sid`. Session nur starten, wenn ein Formular oder der Admin sie braucht.
- Client-IP: nur `REMOTE_ADDR`, außer die Anfrage kommt aus `TRUSTED_PROXIES` (Traefik), dann erster nicht vertrauenswürdiger Eintrag aus `X-Forwarded-For`.
- Uploads (Bewerbungen) außerhalb des Webroots in `storage/uploads`, verschlüsselt mit libsodium (`APP_KEY`), Typprüfung per `finfo`, Größenlimit.

## 5. Konfiguration

`.env` (nie im Repository, Vorlage `.env.example`). Variablen:

```
APP_ENV=development|staging|production
APP_URL=https://www.muellerhv.de
APP_KEY=base64:...            # 32 Byte, für Verschlüsselung (sodium)
STORAGE_MODE=datei            # datei (Standard seit 24.09.2026: keine Datenbank, Leads per Webhook an n8n, docs/n8n-webhook.md) oder datenbank
SHOW_DRAFTS=false             # true nur auf Staging: unfreigegebene Wissensartikel mit Entwurfskennzeichnung zeigen
PRICE_INDICATION_ENABLED=false
DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD   # nur bei STORAGE_MODE=datenbank
MAIL_HOST, MAIL_PORT, MAIL_USER, MAIL_PASSWORD, MAIL_ENCRYPTION=tls, MAIL_FROM, MAIL_FROM_NAME
LEAD_NOTIFY_TO=               # [Empfängeradresse Leads festlegen]
BEWERBUNG_NOTIFY_TO=          # optional, Bewerbungen im Dateimodus (mit PDF), leer = LEAD_NOTIFY_TO
N8N_WEBHOOK_URL=, N8N_WEBHOOK_SECRET=
TRUSTED_PROXIES=172.30.90.0/24  # nur Subnetz des Traefik-Netzes, siehe docs/betrieb.md 1.4
N8N_WEBHOOK_ALLOW_HTTP_INTERNAL=false  # Produktion: nur https, true erlaubt http an interne Hosts
ADMIN_IP_ALLOWLIST=           # leer = keine IP-Beschränkung, sonst CIDR-Liste
SESSION_IDLE_TIMEOUT=1800
LEAD_RETENTION_DAYS=          # [Aufbewahrungsfrist festlegen], leer = Löschlauf deaktiviert
OUTBOX_MODE=worker            # worker (Dienst bzw. Cronjob) oder inline (Webhosting ohne Cronjob, docs/deploy-sftp.md)
SETUP_TOKEN=                  # Web-Einrichtung /_einrichtung/, mindestens 32 Zeichen, leer = gesperrt
STAGING_BASIC_AUTH=           # benutzer:hash (bcrypt/Argon2 prüft die Anwendung, apr1 prüft Traefik)
```

Fachliche Stammdaten liegen in PHP-Konfigurationsdateien (`config/*.php`, geben Arrays zurück):

- `config/unternehmen.php`: Firma, Anschrift, Registergericht, HRB, Geschäftsführer, Gründung (04.03.2020), E-Mail `info@muellerhv.de`, `telefon => null`, `notfall_telefon => null`, `ust_id => null`, Mitgliedschaften, `portal_url => null`. `null` bedeutet: Platzhalter anzeigen, nie erfinden.
- `config/kennzahlen.php`: `stichtag => '2026-07-01'`, `einheiten => 869`, `objekte => 67`, `gruendungsjahr => 2020`. Anzeige immer mit Stichtag.
- `config/freigaben.php`: je Seite `status` (`entwurf`|`freigegeben`), `datum`, `durch`. Nicht freigegebene Seiten zeigen außerhalb der Produktion ein Entwurfsband.
- `config/seiten.php`: Meta je Seite (Titel, Beschreibung, Breadcrumb-Eltern, Schema-Typ, OG-Bild-Titel).

## 6. Twig-Vertrag

Globale Variablen: `app` (`env`, `url`, `is_production`, `show_drafts`, `price_indication`), `firma` (aus unternehmen.php), `kennzahlen`, `nav` (aus navigation.php), `request` (`path`, `query`).
Seitenvariable `page`: `slug`, `title`, `description`, `canonical`, `breadcrumbs` (Liste `{label, url}`), `freigabe`, `og_image`, `schema` (Liste von Arrays, als JSON-LD ausgegeben).

Funktionen: `asset('app.css')` (löst Hash aus Manifest auf), `csp_nonce()`, `csrf_field()`, `csrf_token()`, `placeholder('Telefonnummer bestätigen')` (rendert `<span class="placeholder">[Telefonnummer bestätigen]</span>`), `datum(value)` (TT.MM.JJJJ), `betrag(value)` (1.234,56 EUR), `tel_href(nummer)`, `json_ld(data)`.

Layout `templates/layouts/base.html.twig` stellt Blöcke bereit: `title`, `meta`, `head_extra`, `body_class`, `hero`, `content`, `scripts`. Partials werden per `{% include 'partials/header.html.twig' %}` eingebunden.

## 7. Design-System (MP 3)

- Nur die Tokens aus MP 3.2, als Custom Properties in `resources/css/00-tokens.css`. Zusätzlich abgeleitete Tokens erlaubt (Abstände, Radien, Schatten, Easing, `--hvm-keil-winkel`), aber keine neuen Farben. Transparenzen nur auf Basis der CI-Farben.
- Kontrastregeln MP 3.2 sind verbindlich: Orange nie als Textfarbe auf Weiß, Primärbutton Orange mit Text `--hvm-ink`, Anthrazit auf Weiß nur ab 24 px oder dekorativ, Sekundärtext auf Weiß in `--hvm-ink`.
- Schrift: `system-ui, "Helvetica Neue", Helvetica, Arial, sans-serif`. Keine Webfonts.
- Kennlinie (MP 3.4.1) mit den Segmenten und der Schräge `0,9 × Bandhöhe` aus dem CI-Skill hvm-ci, als CSS, keine Bilder.
- Hausumriss-Geometrie aus dem CI-Skill: offener Umriss (unten offen), Breite 115, Wandhöhe 62, Dachhöhe 42 (relative Einheiten), runde Linienenden und -verbindungen.
- CSS-Klassenpräfix: keiner, aber BEM-ähnlich: `.c-button`, `.c-button--primary`, `.l-container`, `.l-grid`, `.u-visually-hidden`. `c-` Komponente, `l-` Layout, `u-` Utility, `is-`/`has-` Zustände.
- Cascade Layers in dieser Reihenfolge: `@layer reset, tokens, base, layout, components, utilities, overrides;`
- Build: `php bin/build-assets.php` bündelt `resources/css/*.css` (sortiert) zu `public/assets/build/app.[hash].css`, JS-Einstiege zu `public/assets/build/[name].[hash].js`, schreibt `public/assets/build/manifest.json`. In Development liefert `asset()` bei fehlendem Manifest die unbundelten Dateien nicht aus, sondern der Build muss laufen (Composer-Script `composer build`).

## 8. Texte und Inhalte (MP 8)

- Deutsch, sachlich, modern, seriös. Keine Gedankenstriche (U+2012 bis U+2015, U+2212) in Templates, Inhalten, Konfiguration und Doku. `bin/lint-dashes.php` bricht bei Treffern ab. Bereiche als „9 bis 18 Uhr“, nicht mit Strich.
- Keine erfundenen Fakten, Zahlen, Telefonnummern, Preise, Bewertungen, Adressen oder Rechtsangaben. Fehlt etwas: `placeholder('…')` im Template bzw. `[…]` in Markdown, und Eintrag in `docs/offene-punkte.md`.
- Aussagen aus der Altseite, die nicht belegt sind (Eigentümerportal mit Ticketsystem, 24/7-Notdienst mit Handwerkerpool, hauseigene Techniker, Rahmenverträge, keine Aufnahmegebühr), nur mit Kennzeichnung „[bestätigen]“ bzw. als offener Punkt.
- Keine Kundenstimmen ohne belegbare Herkunft. `config/kundenstimmen.php` ist leer, der Abschnitt wird dann ausgeblendet (außerhalb Produktion Platzhalterhinweis).
- Kennzahl „19 Experten“ und „Kundenfokus 80 %“ werden nicht verwendet.

## 9. Seiten und URLs

| URL | Template | Kern-CTA |
|---|---|---|
| `/` | pages/start | Angebot anfordern → `/angebot/` |
| `/weg-verwaltung/` | pages/weg-verwaltung | `/angebot/?art=weg` |
| `/mietverwaltung/` | pages/mietverwaltung | `/angebot/?art=miet` |
| `/se-verwaltung/` | pages/se-verwaltung | `/angebot/?art=se` |
| `/verwalterwechsel/` | pages/verwalterwechsel | Wechsel anfragen → `/angebot/?anlass=wechsel` |
| `/vermietung/` | pages/vermietung | `/kontakt/?anliegen=vermietung` |
| `/verkauf/` | pages/verkauf | `/kontakt/?anliegen=verkauf` |
| `/wertgutachten/` | pages/wertgutachten | `/kontakt/?anliegen=gutachten` |
| `/wissen/` | pages/wissen (Übersicht, Suche, FAQ nach Zielgruppen) | kontextbezogen |
| `/wissen/{slug}/` | pages/wissen-artikel | kontextbezogen |
| `/betreuungsgebiete/` | pages/betreuungsgebiete (StadtController::index) | `/angebot/` mit Region |
| `/hausverwaltung-{slug}/` | pages/stadt (StadtController::show, content/staedte/{slug}.md, 42 Städte aus config/staedte.php) | `/angebot/?region=<Stadt>` |
| `/referenzen/` | pages/referenzen | `/angebot/` |
| `/ueber-uns/` | pages/ueber-uns | `/kontakt/` |
| `/karriere/` | pages/karriere | `/karriere/bewerbung/` |
| `/service/` | pages/service | Portal öffnen |
| `/notfall/` | pages/notfall | Anrufen |
| `/kontakt/` | pages/kontakt | Nachricht senden |
| `/angebot/`, `/angebot/danke/` | pages/angebot, pages/angebot-danke | Absenden |
| `/impressum/`, `/datenschutz/`, `/barrierefreiheit/` | Pflichtseiten | keiner |
| `/styleguide/` | pages/styleguide, nur wenn `APP_ENV` nicht production | keiner |
| `/admin/…` | admin/* | intern |
| `/sitemap.xml`, `/robots.txt` | dynamisch | |

Alte URLs werden über `config/redirects.php` per 301 umgeleitet (Liste und Begründung in `docs/redirects.md`).

## 10. Datenbank

Gilt nur bei `STORAGE_MODE=datenbank`. Im Standardbetrieb (`STORAGE_MODE=datei`, Entscheidung vom 24.09.2026) hat
die Webseite keine Datenbank: Formulare legen verschlüsselte Aufträge in `storage/outbox` an
(`Hvm\Service\DateiAnfrageService`, `Hvm\Service\FileOutbox`), die per signiertem Webhook an n8n und per Mail
versendet und danach gelöscht werden; Rate Limit über `storage/ratelimit`, kein Admin-Bereich (404).

- Migrationen: `migrations/NNNN_name.sql`, ausgeführt von `bin/migrate.php`, Protokoll in Tabelle `schema_migrations`. Bestehende Migrationen nie ändern, immer neue anlegen.
- Engine InnoDB, `utf8mb4_unicode_ci`, Zeitstempel als `DATETIME` in UTC.
- Tabellen gemäß MP 6.3 plus technische Tabellen `outbox` (Webhook und Mail mit Retry), `rate_limits`, `admin_login_attempts`.
- Tests laufen gegen eine eigene Datenbank `hvm_test` (Variablen `DB_TEST_*`).

## 11. Tests und Prüfungen

- `composer test` → PHPUnit (Unit und Integration).
- `composer lint` → `php -l` über src, bin, config; `bin/lint-dashes.php`; `bin/check-placeholders.php --report`.
- `npm run e2e` → Playwright gegen lokalen Server (`php -S` mit `public/index.php` als Router oder Docker), inklusive axe-Prüfung aller Seiten, Header-Test, Redirect-Test, Formularstrecke mit und ohne JavaScript.
- `php bin/check-pii.php --base-url=…` ruft alle Seiten aus der Sitemap ab und bricht bei nicht freigegebenen E-Mail-Adressen oder Telefonnummern ab (Whitelist aus `config/unternehmen.php`).

## 12. Git

- Commits klein und beschreibend, deutsch oder englisch einheitlich je Commit. Keine Zugangsdaten, keine personenbezogenen Daten, kein `/legacy`, kein `vendor`, kein `node_modules`, kein Build-Output.
