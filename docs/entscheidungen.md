# Entscheidungen

Architektur- und Paketentscheidungen mit Begründung. Grundlage: `docs/architektur.md` (verbindlich). Neue Einträge unten anfügen, bestehende nicht umschreiben, sondern durch neue Einträge ablösen.

## Pakete

| Datum | Paket | Version | Begründung |
|---|---|---|---|
| 22.09.2026 | `twig/twig` | ^3 (installiert 3.29) | Auto-Escaping für HTML, `strict_variables` für frühe Fehler außerhalb der Produktion, ausgereift und sicherheitsgepflegt. Architektur Abschnitt 1. |
| 22.09.2026 | `phpmailer/phpmailer` | ^6 | SMTP mit TLS ohne weitere Abhängigkeiten. Wird von der Leadstrecke genutzt. |
| 22.09.2026 | `league/commonmark` | ^2 | Wissensartikel als Markdown mit Frontmatter (MP 7), sichere Standardkonfiguration. |
| 22.09.2026 | `symfony/yaml` | ^7 | Frontmatter und FAQ-Dateien in YAML (`content/faq`). |
| 22.09.2026 | `phpunit/phpunit` (dev) | ^11 | Unit- und Integrationstests. |

Nicht verwendet: Router-Pakete, DI-Container, dotenv-Pakete. Die benötigten Funktionen sind klein und ohne Abhängigkeit testbar (siehe unten).

`composer.json` setzt `config.platform.php = 8.3.0`, damit `composer.lock` auch lokal unter PHP 8.4 nur Versionen enthält, die im Image `php:8.3-fpm` laufen.

## Architektur

| Datum | Entscheidung | Begründung |
|---|---|---|
| 22.09.2026 | Eigener `.env`-Parser `Hvm\Support\Env` | Wenige Zeilen, keine Abhängigkeit. Reihenfolge: Laufzeitüberschreibung (Tests), echte Umgebungsvariablen (Docker `env_file`), `.env`-Datei. Unterstützt Kommentare, `export`, einfache und doppelte Anführungszeichen, `${VAR}`. |
| 22.09.2026 | Fehlendes oder unbekanntes `APP_ENV` gilt als `production` | Sichere Voreinstellung: ohne Konfiguration keine Fehlerdetails, kein Styleguide. |
| 22.09.2026 | Kleiner Dienstcontainer mit Autowiring (`Hvm\Support\Container`) | Controller späterer Module (Angebot, Kontakt, Admin) erhalten Abhängigkeiten über Konstruktor-Typen, ohne den Kernel zu ändern. Dienste mit Konfigurationswerten registriert der Kernel per Fabrik. Keine Paketabhängigkeit. |
| 22.09.2026 | Middleware-Schnittstelle `process(Request, callable $next): Response` | Einfacher als PSR-15, ausreichend für die feste Kette laut Architektur Abschnitt 3. |
| 22.09.2026 | ErrorHandler ergänzt Sicherheitsheader über einen Decorator | Der ErrorHandler liegt außen, Ausnahmen laufen an SecurityHeaders vorbei. Damit auch 500-Antworten CSP, HSTS und `X-Robots-Tag` tragen, wendet der ErrorHandler `SecurityHeaders::apply()` auf seine Antwort an. |
| 22.09.2026 | 500-Seite in Produktion über Twig (`pages/500.html.twig`), Rückfall auf statisches HTML | Einheitliches Erscheinungsbild; schlägt Twig selbst fehl, bleibt eine generische Seite ohne Details. |
| 22.09.2026 | `X-Robots-Tag: noindex, nofollow` für `/admin/` auch in Produktion | Interner Bereich darf nie im Index erscheinen, zusätzlich zu `Disallow: /admin/` in robots.txt. |
| 22.09.2026 | Sitzung: Klasse `Hvm\Http\Session` mit Treibern `native` und `array` | Start nur bei Bedarf (POST, Formular- und Adminpfade laut `config/app.php`, vorhandenes Cookie oder `csrf_field()` im Template). Seiten ohne Formular bleiben cookiefrei und cachebar. Treiber `array` für Tests (`SESSION_DRIVER=array`). Leerlauf-Timeout aus `SESSION_IDLE_TIMEOUT`. PHP-Cache-Limiter ist abgeschaltet, Seiten mit Sitzung erhalten `Cache-Control: no-store, private`. |
| 22.09.2026 | CSRF-Token-Logik in `Hvm\Http\Middleware\Csrf` | Token je Sitzung (64 Hex-Zeichen), Prüfung aller nicht sicheren Methoden mit `hash_equals`, Feld `_csrf` oder Header `X-CSRF-Token`. Ausnahmen nur über `app.csrf_exempt` für signierte Maschinenschnittstellen. Das in der Architektur genannte `Hvm\Security\Csrf` kann diese Klasse später kapseln. |
| 22.09.2026 | Weiterleitungen als Liste mit `typ` exakt oder praefix | Eine Datei für Code und Dokumentation (`docs/redirects.md`), Verifikationsstatus je Eintrag. LegacyRedirects läuft vor TrailingSlash, dadurch keine Weiterleitungsketten. |
| 22.09.2026 | Route `/styleguide/` per Option `nicht_in => ['production']` | Die Route wird in Produktion gar nicht registriert, Aufruf ergibt 404. |
| 22.09.2026 | `/wissen/{slug}/` noch nicht registriert | Ein Platzhalter über den PageController würde für beliebige Slugs Status 200 liefern (Soft-404). Der WissenController registriert die Route mit echter Existenzprüfung. |
| 22.09.2026 | Sitemap-Erweiterung über `Hvm\Support\SitemapProvider` und `app.sitemap_providers` | Wissensartikel und spätere dynamische Seiten liefern ihre Adressen, ohne den SeoController zu ändern. Statische Seiten erscheinen, wenn `config/seiten.php` `sitemap => true` setzt. |
| 22.09.2026 | Seitenmetadaten über `Hvm\View\PageMeta` und `Hvm\View\SchemaBuilder` | PageController und ErrorController sowie spätere Controller bauen die Variable `page` einheitlich (Canonical aus `APP_URL`, Breadcrumbs aus `eltern`, Freigabe, OG-Bild, strukturierte Daten). Felder mit `null` in `config/unternehmen.php` (Telefon, USt-IdNr.) werden im JSON-LD weggelassen, nie geraten. |
| 22.09.2026 | `json_ld()` mit `JSON_HEX_TAG`, `JSON_HEX_AMP`, `JSON_HEX_APOS`, `JSON_HEX_QUOT` | `<`, `>`, `&` und Anführungszeichen werden als Unicode-Escape ausgegeben, ein Ausbruch aus `<script>` ist ausgeschlossen. U+2028 und U+2029 bleiben escaped. |
| 22.09.2026 | `betrag()` mit normalem Leerzeichen vor „EUR“ | Exakt das vorgegebene Format „1.234,56 EUR“. Ein geschütztes Leerzeichen kann das Designsystem per CSS oder eigenem Filter ergänzen. |
| 22.09.2026 | Asset-Build ohne Node (`bin/build-assets.php`) | CSS wird gebündelt und konservativ minimiert (Kommentare, Leerraum, Zeichenketten bleiben unverändert). JS wird nicht gebündelt, sondern vollständig in ein Verzeichnis mit Inhalts-Hash kopiert (`public/assets/build/js/<hash>/`). Relative ES-Modul-Importe funktionieren unverändert, jede Datei ist versioniert und darf mit `immutable` gecacht werden. Fehlende Einstiege werden als leeres Modul erzeugt. |
| 22.09.2026 | `asset()` ohne Manifest liefert den ungehashten Pfad | Keine Ausnahme im Template, fehlender Build fällt im Browser sofort auf. Vorgabe bleibt: vor dem Start `composer build`. |
| 22.09.2026 | Migrationen mit eigenem SQL-Zerleger | Mehrere Anweisungen je Datei, Zeichenketten und Kommentare werden beachtet. Protokoll in `schema_migrations`. DDL ist in MariaDB nicht transaktional, daher Abbruch mit Hinweis statt Rollback. Option `--path` für Tests. |
| 22.09.2026 | Log-Filter für E-Mail und Telefon | `Log::redact()` ersetzt E-Mail-Adressen und Ziffernfolgen ab sieben Ziffern (übliche Trennzeichen), ISO-Datumsangaben und UUIDs bleiben erhalten. Namen und Anschriften sind technisch nicht zuverlässig erkennbar, Aufrufer übergeben sie grundsätzlich nicht. |
| 22.09.2026 | Docker: ein Dockerfile mit Zielen `app` (PHP-FPM) und `web` (Nginx) | Das Nginx-Image enthält das öffentliche Verzeichnis inklusive Build aus demselben Stand. Kein geteiltes Volume für Code oder Assets. |
| 22.09.2026 | `storage/cache` nicht als Volume | Kompilierte Twig-Templates gehören zum Image-Stand. Persistiert werden nur `storage/logs`, `storage/uploads`, `storage/ratelimit`. |
| 22.09.2026 | PHP-FPM `clear_env = no` | Sonst sieht PHP die per `env_file` gesetzten Variablen nicht. |
| 22.09.2026 | Sitzungsdaten im Container unter `/tmp` | Eine PHP-Instanz, Neustart beendet Sitzungen (Admin meldet sich neu an). Bei mehreren Instanzen auf gemeinsamen Speicher umstellen. |
| 22.09.2026 | Traefik-Middleware für Staging (Basic Auth, `X-Robots-Tag`) über Variablen, Standard-Middleware Kompression | Produktion und Staging nutzen dieselbe Compose-Datei mit eigener `.env`. Eine leere Middleware-Liste kann Router in Traefik ungültig machen, daher eine harmlose Standard-Middleware. |
| 22.09.2026 | `.dockerignore` angelegt | Verhindert, dass `.env`, `vendor`, `legacy` oder `storage` ins Image gelangen. Das Dockerfile löscht `.env` zusätzlich. |
| 22.09.2026 | `bin/lint-dashes.php` prüft zusätzlich `README.md` | README ist Dokumentation und unterliegt denselben Textregeln. |
| 23.09.2026 | Produktion startet nicht ohne gültigen `APP_KEY` | `Kernel::fromGlobals()` bricht bei `APP_ENV=production` ab, wenn `APP_KEY` fehlt, nicht dekodierbar ist oder kürzer als 32 Byte ist (gilt für Web und `bin/`-Skripte). `SpamGuard::deriveKey()` verwendet den öffentlich ableitbaren Ersatzschlüssel nur noch außerhalb der Produktion. Sicherheitsreview 23.09.2026. |
| 23.09.2026 | Fehlerdetails nur bei `APP_ENV=development` | Staging ist öffentlich erreichbar (Basic Auth optional) und zeigt wie Produktion die generische 500-Seite. |
| 23.09.2026 | Rate Limiting und IP-Sperre im Admin je IPv6-/64 | `IpRange::clientKey()` fasst IPv6-Adressen auf ihr /64-Präfix zusammen, IPv4-gemappte Adressen gelten als IPv4. Sonst ließe sich jedes Limit durch Adresswechsel innerhalb eines Anschlusses umgehen. |
| 23.09.2026 | Admin-Anmeldeversuche je Konto serialisiert (MariaDB `GET_LOCK`) | Sperrprüfung, Passwort- bzw. TOTP-Prüfung und Zählung laufen je Konto nacheinander. Ohne diese Klammer passierten parallel gestartete Versuche alle die Sperrprüfung (reproduziert: 12 von 12 geprüft statt höchstens 5). |
| 23.09.2026 | `DB_ROOT_PASSWORD` in den Diensten php, worker und backup geleert | `env_file` reicht die gesamte `.env` durch. Das Root-Passwort braucht nur der Dienst db. |
