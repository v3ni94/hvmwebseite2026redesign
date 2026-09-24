# Prüfbericht Qualitätssicherung, 23.09.2026

Vollständige Qualitätsprüfung vor Go-Live: Build, automatisierte Tests, Playwright-E2E inkl. axe (WCAG 2.2 AA), PII-, Header- und Platzhalter-Prüfung in development und in einer Produktions-Simulation, sowie Lighthouse mobil. Nichts wurde committet. Änderungen beschränken sich auf zwei Playwright-Testdateien (siehe Abschnitt 6).

## 1. Build

`composer build` (Assets, OG-Bilder, Suchindex) lief fehlerfrei durch: CSS-Bundle 61.612 Byte, Admin-CSS 9.512 Byte, 9 JS-Dateien, 21 OG-Bilder, Suchindex mit 58 Einträgen.

## 2. Automatisierte Tests (PHPUnit)

- Vorher (Ausgangsstand dieser Prüfung, nach den vorangegangenen Sicherheits- und Inhaltsänderungen): `composer test` lief bereits grün.
- Nachher (nach `composer build` und den Korrekturen dieser Prüfung): **OK, 419 Tests, 1.901 Assertions.**
- `php bin/lint-dashes.php`: Keine Gedankenstriche gefunden (182 Dateien geprüft).
- `php bin/check-placeholders.php --report`: 42 Platzhalter gefunden, davon 42 offen, 0 bewusst freigegeben (unverändert gegenüber dem Ausgangsstand, siehe Abschnitt 7). Exit-Code 0, kein Baustein blockiert den Build.

## 3. Playwright E2E (Chromium unter /opt/pw-browsers, Desktop 1440 px und Pixel 7 mobil), inkl. axe WCAG 2.2 AA

- **Vorher:** 142 Tests, davon 140 bestanden, **2 fehlgeschlagen** (nur im Projekt chromium-mobile):
  - `404.spec.js`: Die Kern-CTA auf der 404-Seite wurde als nicht sichtbar gemeldet.
  - `navigation.spec.js`: Tastaturbedienung des Untermenüs (Enter öffnet, Escape schließt) schlug fehl.
- **Nachher:** **142 von 142 Tests bestanden**, keine Fehler mehr.
- axe (WCAG 2.0 bis 2.2 AA, alle öffentlichen Seiten laut Sitemap-Test in `pages.spec.js`, Desktop und Mobil): **keine Befunde mit Schweregrad serious oder critical**, weder vorher noch nachher. Die beiden Fehlschläge betrafen keine axe-Prüfungen.
- Redirects (`redirects.spec.js`), Header-Pflichtangaben je Seite (`pages.spec.js`), 404/410-Verhalten (`404.spec.js`), Mobile Bottom-Bar und Skip-Link (`navigation.spec.js`): alle bestanden.

Ursachenanalyse der zwei Fehlschläge (kein Produktionsfehler, sondern Testfehler):

1. **404-Seite:** Der Selektor `a[href="/angebot/"]` traf zuerst auf die CTA in der Kopfzeile (`c-nav__cta`), die unterhalb von 72 em per CSS (`.c-nav { display: none; }`) bewusst verborgen ist, weil auf Mobilgeräten stattdessen die Bottom-Bar und die Fuß-Navigation greifen. Die eigentlich sichtbare CTA im Seiteninhalt der 404-Seite (`.c-fehlerseite__wege`) wurde vom Test gar nicht geprüft. Korrektur: Der Test zielt jetzt gezielt auf `.c-fehlerseite__wege a[href="/angebot/"]`.
2. **Tastaturnavigation:** Die Hauptnavigation mit den Untermenü-Buttons (`data-nav-toggle`) ist laut Designsystem ebenfalls erst ab 72 em sichtbar; auf der Pixel-7-Breite war der fokussierte Button unterhalb der sichtbaren, interaktiven Fläche. Korrektur: Der Test setzt jetzt vor `page.goto()` eine feste Desktop-Breite (1440 × 900 px), wie es die übrigen breitenabhängigen Tests in derselben Datei bereits für ihre Fälle tun.

Beide Korrekturen ändern nur die Testerwartung an die vorhandene, im Designsystem dokumentierte responsive Navigation, nicht das Anwendungsverhalten selbst.

**Offen, nicht behoben:** Es gibt keine dedizierten Playwright-Tests, die dieselbe Formularstrecke (Angebot) einmal mit aktivem JavaScript und einmal mit deaktiviertem JavaScript im Browser durchspielen. Die JS-freie Mehrschritt-Logik des Angebotsformulars ist serverseitig durch `tests/Integration/AngebotFlowTest.php` abgedeckt (PHP-Ebene, kein Browser), die JS-gestützte Mehrschritt-Navigation nur implizit über die normalen Playwright-Läufe (JS aktiv). Empfehlung: einen Playwright-Test mit `javaScriptEnabled: false` für die Angebotsstrecke ergänzen.

## 4. PII- und Header-Prüfung, development und Produktions-Simulation

Produktions-Simulation: eigener `php -S`-Prozess mit `APP_ENV=production` und den übrigen Variablen ausschließlich als Prozessumgebung übergeben, `.env` blieb unverändert.

| Prüfung | development | Produktions-Simulation |
|---|---|---|
| `php bin/check-pii.php` | 23 Seiten geprüft, 0 Befunde | 23 Seiten geprüft, 0 Befunde |
| `php bin/check-headers.php` | 18 Adressen geprüft, 0 Befunde | 18 Adressen geprüft, 0 Befunde |

Sicherheits-Header wurden stichprobenartig verglichen: In der Produktions-Simulation erscheint zusätzlich `Strict-Transport-Security: max-age=31536000; includeSubDomains`, und `X-Robots-Tag: noindex, nofollow` entfällt (in development bewusst gesetzt). CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` und `Cross-Origin-Opener-Policy` sind in beiden Umgebungen identisch gesetzt.

## 5. Lighthouse mobil (Chromium 1194, CHROME_PATH auf /opt/pw-browsers)

Erster Lauf gegen den development-Server ergab in allen vier Seiten SEO 69, einzig wegen des Audits „is-crawlable" (Seite ist absichtlich per `X-Robots-Tag: noindex` von der Indexierung ausgeschlossen, siehe Abschnitt 4). Das ist kein Fehler, sondern die korrekte Absicherung von development gegen Indexierung. Maßgeblich für das Ziel „Punktzahl je Kategorie ab 95" ist daher die Produktions-Simulation:

| Seite | Performance | Accessibility | Best Practices | SEO |
|---|---|---|---|---|
| / | 99 | 100 | 100 | 100 |
| /verwalterwechsel/ | 99 | 100 | 100 | 100 |
| /wissen/ | 99 | 100 | 100 | 100 |
| /angebot/ | 99 | 100 | 100 | 100 |

Alle vier Werte je Seite liegen bei 99 oder 100, also über der Schwelle von 95. Kein Punkt musste im Code nachgebessert werden. Der einzige nicht volle Wert (Performance 99 auf allen vier Seiten) liegt an einem geringfügigen Diagnose-Hinweis (keine Ladeblockade, keine reale Verzögerung messbar) und wurde als unterhalb der 95er-Schwelle nicht als Korrekturpflicht gewertet, da 99 ≥ 95.

## 6. Seitengewicht Startseite ohne Bilder

Gemessen in der Produktions-Simulation (Lighthouse Network-Requests, mobil, komprimierte Übertragungsgröße):

- Gesamtes Seitengewicht: 144,4 KB (13 Requests)
- Bildanteil (SVG/PNG/ICO, u. a. Logo, Favicon, App-Icon): 30,5 KB
- **Seitengewicht ohne Bilder: 113,8 KB**

Größter Einzelposten ohne Bilder ist das CSS-Bundle mit 61,8 KB, gefolgt vom HTML-Dokument mit 38,2 KB und den JS-Modulen (zusammen rund 16 KB).

## 7. Geänderte Dateien in dieser Prüfung

- `tests/e2e/404.spec.js`: Selektor auf die tatsächlich sichtbare Kern-CTA im Seiteninhalt präzisiert.
- `tests/e2e/navigation.spec.js`: Feste Desktop-Breite für den Tastaturtest der Hauptnavigation ergänzt, passend zum bestehenden Muster der breitenabhängigen Tests in derselben Datei.
- `docs/pruefbericht.md`: dieser Bericht (neu).

Nicht verändert, da außerhalb des Auftrags dieser Prüfung oder bereits an anderer Stelle dokumentiert: die 42 offenen Platzhalter aus `docs/platzhalter-report.md` (unverändert von Vorabprüfungen übernommen, benötigen Angaben der Geschäftsführung, siehe dort), sowie die in vorangegangenen Reviews benannten offenen Punkte zu Sicherheit (u. a. IP-Sperre über mehrere Konten, Timing bei unbekannten Admin-Konten, TRUSTED_PROXIES-Härtung) und Inhalten (u. a. Markdown-Links in der Wissen-Suche, IVD-Langform, Team-Historie).

## 8. Zusammenfassung vorher/nachher

| Prüfung | Vorher | Nachher |
|---|---|---|
| PHPUnit | 419/419 grün | 419/419 grün |
| lint-dashes | 0 Treffer | 0 Treffer |
| Playwright gesamt | 140/142 grün | **142/142 grün** |
| axe serious/critical | 0 | 0 |
| Lighthouse SEO (Produktions-Simulation, 4 Seiten) | 100/100/100/100 | 100/100/100/100 (unverändert, bereits konform) |
| Lighthouse Performance/Accessibility/Best Practices | je 99/100/100 | unverändert |
| PII-Befunde (dev und Produktions-Simulation) | 0 | 0 |
| Header-Befunde (dev und Produktions-Simulation) | 0 | 0 |

## 9. Empfehlung

Aus technischer und werbewirtschaftlicher Sicht ist die Seite freigabefähig, vorbehaltlich der in Abschnitt 7 benannten inhaltlichen und sicherheitsrelevanten offenen Punkte aus den vorangegangenen Prüfungen, die eine Freigabe durch die Geschäftsführung beziehungsweise Rücksprache mit Rechtsanwalt oder Steuerberater erfordern. Diese Prüfung selbst hat keine Freigabe-relevanten Verfahrenshandlungen ausgelöst; es wurde nichts committet.

**Zu verifizieren:** Alle in diesem Bericht genannten Kennzahlen stammen aus lokalen Testläufen vom 23.09.2026 gegen `php -S`-Instanzen dieser Prüfung und sind vor Go-Live gegen die tatsächliche Staging-/Produktionsumgebung zu wiederholen.

## 10. Nachtrag 23.09.2026: technische offene Punkte

- Webhook: In `APP_ENV=production` nur https; http nur für interne Hosts mit `N8N_WEBHOOK_ALLOW_HTTP_INTERNAL=true`. Unzulässige Adressen versenden nichts, der Outbox-Eintrag bleibt mit Hinweis ohne URL und ohne Leaddaten zurückgestellt.
- Admin-Anmeldung: Dummy-Hash für unbekannte Konten als Konstante (Argon2id mit PHP-Standardparametern), keine Hash-Erzeugung beim ersten unbekannten Konto mehr.
- `TRUSTED_PROXIES`: Beispiel auf das Subnetz des Traefik-Netzes eingeschränkt (`172.30.90.0/24`), internes Netz `backend` mit festem Subnetz (`BACKEND_SUBNET`), Ablauf in `docs/betrieb.md` Abschnitt 1.4.
- E2E: neue Browsertests `tests/e2e/angebot.spec.js` (mit und ohne JavaScript) und `tests/e2e/kontakt.spec.js`, 8 Tests über zwei Projekte. Die Zeitfalle wird abgewartet (4,6 s), das Rate Limit über je Test eigene fiktive Adressen in `X-Forwarded-For` (Testserver vertraut nur 127.0.0.1) entkoppelt.
- `bin/check-pii.php` wertet Adressen unter reservierten Beispieldomains (RFC 2606) nicht als Befund; die Beispielnummer aus den Fehlertexten der Telefonfelder steht kommentiert in `config/pii-whitelist.php`.
- CI: `.github/workflows/ci.yml` mit den Jobs PHP 8.3, Playwright und Prüfungen, ohne Secrets.

Lokale Ergebnisse nach dem Nachtrag: siehe Bericht dieser Sitzung (PHPUnit, Playwright, lint-dashes, PII). Vor Livegang in der Staging-Umgebung zu wiederholen.


## 11. Nachtrag 23.09.2026: SEO, GEO und Performance

Details und Entscheidungen in `docs/seo-geo.md`. Lighthouse 12 mit produktionsnaher Auslieferung (`APP_ENV=production`, gzip wie Nginx), Artikel auf Staging, da noch nicht freigegeben:


| Seite (mobil) | Perf. | Access. | Best Pr. | SEO | LCP | CLS | TBT | Gewicht | Requests |
|---|---|---|---|---|---|---|---|---|---|
| / | 100 | 100 | 100 | 100 | 1,28 s | 0,000 | 8 ms | 39 KB | 15 |
| /weg-verwaltung/ | 100 | 100 | 100 | 100 | 1,33 s | 0,000 | 0 ms | 39 KB | 15 |
| /verwalterwechsel/ | 100 | 100 | 100 | 100 | 1,32 s | 0,004 | 0 ms | 39 KB | 15 |
| /asset-management/ | 100 | 100 | 100 | 100 | 1,31 s | 0,000 | 0 ms | 39 KB | 15 |
| /wissen/ | 100 | 100 | 100 | 100 | 1,30 s | 0,000 | 0 ms | 37 KB | 15 |
| /wissen/hausgeld/ (1) | 100 | 100 | 100 | 69 | 1,32 s | 0,000 | 1 ms | 40 KB | 15 |
| /angebot/ | 100 | 100 | 100 | 100 | 1,31 s | 0,005 | 0 ms | 45 KB | 18 |
| /fakten/ | 100 | 100 | 100 | 100 | 1,27 s | 0,000 | 0 ms | 37 KB | 15 |

| Seite (Desktop) | Perf. | Access. | Best Pr. | SEO | LCP | CLS | TBT | Gewicht | Requests |
|---|---|---|---|---|---|---|---|---|---|
| / | 100 | 100 | 100 | 100 | 0,37 s | 0,000 | 0 ms | 39 KB | 15 |
| /weg-verwaltung/ | 100 | 100 | 100 | 100 | 0,37 s | 0,000 | 0 ms | 39 KB | 15 |
| /verwalterwechsel/ | 100 | 100 | 100 | 100 | 0,36 s | 0,000 | 0 ms | 39 KB | 15 |
| /asset-management/ | 100 | 100 | 100 | 100 | 0,35 s | 0,000 | 0 ms | 39 KB | 15 |
| /wissen/ | 100 | 100 | 100 | 100 | 0,36 s | 0,000 | 0 ms | 37 KB | 15 |
| /wissen/hausgeld/ (1) | 100 | 100 | 100 | 69 | 0,36 s | 0,000 | 0 ms | 40 KB | 15 |
| /angebot/ | 100 | 100 | 100 | 100 | 0,38 s | 0,001 | 0 ms | 56 KB | 19 |
| /fakten/ | 100 | 100 | 100 | 100 | 0,37 s | 0,000 | 0 ms | 37 KB | 15 |

(1) SEO 69 nur wegen `noindex` auf Staging. Ohne Kompression (`php -S` direkt): Performance 98 bis 100, LCP mobil 1,95 bis 2,10 s.

- Strukturierte Daten: `php bin/check-structured-data.php` ohne Fehler (staging 76 Seiten, production 23 Seiten).
- CLS `/angebot/` mobil von 0,062 auf 0,005 (Stepper-Platz vorab reserviert).
- Neue Tests: `tests/Unit/Controller/SeoControllerTest.php` (robots.txt mit KI-Crawlern und Schalter, llms.txt, Sitemap), `tests/Unit/Controller/FaktenControllerTest.php`, `tests/Unit/Seo/SeoMetaTest.php` (Titel- und Beschreibungslängen, Kurzfassung).

## 12. Nachtrag 24.09.2026: Code-Review und technische Verbesserungen

### 12.1 Befunde aus dem Code-Review (je mit Nachweis und Test)

| Nr. | Befund | Nachweis vor der Korrektur | Korrektur | Test |
|---|---|---|---|---|
| R1 | Anker der Artikelüberschriften mit Umlaut am Wortanfang falsch: „Übergabe der Unterlagen“ ergab `#bergabe-der-unterlagen` (`strtolower` wandelt Ä, Ö, Ü nicht um) | `curl /wissen/verwalterwechsel-weg/`: `id="bergabe-der-unterlagen"`, ebenso `bertragung-auf-dienstleister` | `mb_strtolower` in `src/Content/MarkdownRenderer.php` | `tests/Unit/Content/MarkdownRendererTest.php` |
| R2 | Angebotsformular: Prüfung mit UTC, Formular mit deutscher Zeit. Am Monatsersten zwischen 0 und 2 Uhr lehnte der Server den im Formular angebotenen spätesten Beginn ab (ebenso Baujahr zum Jahreswechsel) | Test mit `Clock::freeze('2026-09-30 22:30:00')`: Formular `max="2031-10"`, Absenden mit `2031-10` ergab Fehler am Feld | `AngebotController::submit()` prüft mit `Clock::local()` | `AngebotControllerTest::testMonthBoundsFollowGermanTimeAtMonthChange` |
| R3 | `IpRange::matches()`: nicht numerische Präfixlänge (`172.30.90.0/abc`, `/`, `/ 24`) wurde zu `/0` und traf jede Adresse. Ein Tippfehler in `TRUSTED_PROXIES` hätte jede `X-Forwarded-For`-Angabe vertrauenswürdig gemacht, in `ADMIN_IP_ALLOWLIST` die Beschränkung aufgehoben | Test: `matches('203.0.113.5', '172.30.90.0/abc')` lieferte true | nur Ziffern als Präfix (`ctype_digit`) | `IpRangeTest::testInvalidPrefixLengthNeverMatches` |
| R4 | `bin/migrate.php` ohne Sperre: zwei gleichzeitige Läufe scheiterten mit „Table ... already exists“ | drei Durchläufe mit zwei parallelen Prozessen auf leerer Datenbank, jeweils ein Lauf mit Exitcode 1 | `GET_LOCK` je Datenbank, 120 s Wartezeit | `MigrationsTest::testConcurrentMigrationRunsAreSerialized` |
| R5 | Live-Suche Wissen: Trefferliste unsichtbar. Das Template setzt `u-visually-hidden` auf den Ergebnisbereich, die Suche blendete die Abschnitte aus und zeigte die Treffer in einem 1 x 1 px großen Bereich | Playwright: Bereich 1 x 1 px, Abschnitte ausgeblendet | `resources/js/search.js` entfernt die Klasse beim Start | `tests/e2e/robustheit.spec.js` „Trefferliste ist sichtbar“ |
| R6 | Live-Suche: Escape leerte das Feld, die noch geplante Suche (150 ms) lief danach trotzdem und blendete alte Treffer wieder ein; parallele Eingaben während des Ladens luden den Index mehrfach und konnten veraltete Treffer anzeigen | Playwright: nach Escape Feld leer, Abschnitte ausgeblendet, Treffer sichtbar | Timer bei Escape verwerfen, ein gemeinsamer Ladevorgang, Ergebnis nur für den aktuellen Feldwert | `robustheit.spec.js` „Escape verwirft eine noch geplante Suche“ |
| R7 | Angebotsformular: Sendesperre (`data-gesendet`) wurde bei Rückkehr aus dem Back-Forward-Cache nicht aufgehoben, das Formular blieb blockiert (Admin-Formulare hatten die Freigabe bereits) | Playwright mit `pageshow` (`persisted: true`): Sperre blieb gesetzt | `pageshow`-Handler in `resources/js/angebot.js` | `robustheit.spec.js` „Back-Forward-Cache“ |
| R8 | Docker-Image ohne Open-Graph-Bilder und ohne Suchindex: das Dockerfile führte nur `build-assets.php` aus, `public/og` steht in `.dockerignore`, beide Ergebnisse sind nicht im Repository. Produktion hätte `/og/*.png` und `/assets/search-index.json` mit 404 beantwortet | Vergleich `composer.json` (Skript build) mit `docker/php/Dockerfile` | Dockerfile führt alle drei Build-Schritte aus | `DockerComposeTest::testImageRunsCompleteAssetBuild` |
| R9 | Nginx beantwortete alles unter `/.well-known/` ohne Datei mit 404 (`try_files $uri =404`), eine dynamische `security.txt` wäre nie bei PHP angekommen | `docker/nginx/default.conf` | `try_files $uri @php` | `DockerComposeTest::testNginxServesPrecompressedAssetsAndDynamicWellKnown` |
| R10 | Mobiles Menü: Fokus lief per Tab vom Schließen-Button in den verdeckten Seiteninhalt | Playwright: erster Menüpunkt nach dem Öffnen nicht fokussiert, Tab verließ das Menü | Fokus auf ersten Menüpunkt, Fokusfalle, Hintergrund `inert`, Escape gibt Fokus an den Button zurück (`resources/js/modules/kopfzeile.js`) | `tests/e2e/menue-mobil.spec.js` |
| R11 | Instabile Tests der Rate Limits: feste 10-Minuten-Fenster, beim Fensterwechsel während des Tests fehlte der erwartete Status 429 | Lauf endete um 08:40:00 UTC mit Fehler in `KontaktFlowTest:150`, Wiederholung grün | Tests frieren die Zeit mitten im Fenster ein | `KontaktFlowTest`, `AngebotFlowTest`, `BewerbungFlowTest` |

Geprüft ohne Befund: Router, Request (Pfad-Normalisierung, Client-IP), CSRF, Sitzung, Sicherheitsheader, Weiterleitungen, Validierung (ungültiges UTF-8, Arrays statt Zeichenketten), Outbox-Claim, Löschlauf, CSV-Export (Formelinjektion), Admin-Filter (Zeitzonen), Upload-Prüfung, Mailversand. Stichproben mit fehlerhaften Eingaben (`%ff`, Arrays in Query und POST) lieferten 200, 303, 403 oder 422, nie 500.

Hinweis zur Arbeitsweise: Ein Teil dieser Änderungen ist während der Arbeit durch einen parallel arbeitenden Agenten im Commit `eae5a1d` („Zwischenstand, Technikauftrag läuft“) mit eingecheckt worden.

### 12.2 Neue Funktionen

- TOTP-Wiederherstellungscodes: zehn Einmalcodes je Konto, nur als HMAC gespeichert, `bin/admin-user.php create` und `recovery-codes`, bei der Anmeldung anstelle des TOTP-Codes, Verbrauch mit Zeitpunkt und IP-Hash in der Datenbank und im Log, Hinweis auf verbleibende Codes (Tests: `AdminAuthTest`, `AdminFlowTest`).
- `/.well-known/security.txt` nach RFC 9116 (`SeoControllerTest::testSecurityTxtFollowsRfc9116`).
- `/health` für den Docker-Healthcheck (`HealthControllerTest`, `tests/Integration/HealthTest.php`).
- Build mit `.gz`-Varianten und atomarem Tausch des Build-Verzeichnisses (`PrecompressTest`), Nginx mit `gzip_static`, `text/markdown` in `gzip_types`, `llms.txt` mit Markdown-Typ, Cache-Control für `/og/` und `/assets/img/`.
- `bin/deploy-post.sh` (`tests/Integration/DeployPostTest.php`), dokumentiert in `docs/betrieb.md` 1.5.
- CI-Job „Abhängigkeiten“ (`composer audit`, ab medium blockierend; `npm audit --omit=dev --audit-level=moderate`), `.github/dependabot.yml`.
- Formulare: Fehlertext Telefon ohne Beispielnummer („Bitte geben Sie eine gültige Telefonnummer mit Vorwahl an.“), `config/pii-whitelist.php` ohne Telefonnummern.
- Druckansicht `resources/css/90-druck.css` mit `resources/js/modules/druck.js` (FAQ vor dem Druck geöffnet).

### 12.3 Prüfwerte

| Prüfung | Ergebnis |
|---|---|
| `php bin/lint-dashes.php` | keine Gedankenstriche (294 Dateien) |
| `composer build` | ok, 17 Dateien mit `.gz`, 23 OG-Bilder, Suchindex 119 Einträge |
| `vendor/bin/phpunit` | 467 Tests, alle grün |
| `npx playwright test` | 172 Tests grün (Desktop und mobil) |
| `php bin/check-pii.php` (lokal, development) | 29 Seiten, 0 Befunde |
| `php bin/check-headers.php` (lokal) | 24 Adressen, 0 Befunde |
| `php bin/check-structured-data.php` (staging und production) | keine Fehler |
| `composer audit`, `npm audit --omit=dev` | keine Hinweise |

Nicht lokal prüfbar (kein Nginx, kein Docker): `nginx -t` und die Auslieferung vorkomprimierter Dateien, siehe `docs/offene-punkte.md` C17.
