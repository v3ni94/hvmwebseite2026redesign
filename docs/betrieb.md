# Betrieb

Verbindliche Grundlage für Deployment, Rollback, Backup, Wiederherstellung, Monitoring und
Livegang. Ergänzt `docs/architektur.md` und `README.md` (Abschnitte Deployment und Betrieb).
Enthält keine Zugangsdaten, keine Schlüssel und keine personenbezogenen Daten.

## 1. Deployment

Zielumgebung: Docker Compose (PHP-FPM und Nginx aus einem Dockerfile, siehe
`docker/php/Dockerfile`) hinter dem bestehenden Traefik auf dem IONOS Dedicated Server.
Produktion und Staging laufen als getrennte Compose-Projekte mit je eigener `.env` und eigenem
`COMPOSE_PROJECT_NAME`.

### 1.1 Ablauf

1. Auf dem Zielserver `.env` aus `.env.example` pflegen: `APP_ENV`, `APP_URL`, `APP_HOST`,
   `DB_*`, `DB_ROOT_PASSWORD`, `MAIL_*`, `TRAEFIK_*`, `STAGING_*` (nur Staging), `BACKUP_*`.
   `.env` verbleibt ausschließlich auf dem Server, nie im Repository und nie im Image
   (das Dockerfile löscht `.env` im Build zusätzlich).
2. Vor dem Deployment: Datenbanksicherung erstellen (Abschnitt 3), besonders wenn das Release
   Migrationen enthält.
3. Image bauen und starten:
   ```sh
   HVM_TAG=<neuer-tag> docker compose build
   HVM_TAG=<neuer-tag> docker compose up -d
   ```
   `<neuer-tag>` ist eindeutig und nachvollziehbar, zum Beispiel der Git-Commit-Hash oder ein
   Datum. Kein `latest` im produktiven Einsatz, damit ein Rollback ein konkretes Image trifft.
4. Nacharbeiten im laufenden PHP-Container mit `bin/deploy-post.sh` (Abschnitt 1.5):
   ```sh
   docker compose exec php sh bin/deploy-post.sh --ohne-build --url=http://nginx
   ```
   Das Skript führt die Migrationen aus, leert den Twig-Cache und prüft `/health`.
   Migrationen sind ausschließlich vorwärtsgerichtet (`docs/entscheidungen.md`). Ein Rollback
   der Anwendung setzt keine Migration zurück, siehe Abschnitt 2. Gleichzeitige Migrationsläufe
   sind über eine Datenbanksperre (`GET_LOCK`) serialisiert.
5. Der Build ist Teil des Image-Baus (Dockerfile, gleiche Schritte wie `composer build`:
   `bin/build-assets.php` mit `.gz`-Varianten, `bin/build-og-images.php`,
   `bin/build-search-index.php`). Der Nginx-Container hat eine eigene Kopie von `public/`,
   ein Build im laufenden PHP-Container erreicht ihn nicht; deshalb dort `--ohne-build`.
6. Worker (`bin/worker.php`, Outbox für Mail und Webhook) mit eigenem Profil starten, sobald
   die Datei vorhanden ist:
   ```sh
   docker compose --profile worker up -d worker
   ```
7. Nach dem Deployment: Rauchtest laut Abschnitt 4 (Healthcheck, Stichproben-Seiten,
   Formularstrecke im Staging).

### 1.2 Staging

Staging läuft unter `neu.muellerhv.de` (bestätigt 24.09.2026, `APP_HOST=neu.muellerhv.de`,
`APP_URL=https://neu.muellerhv.de`, `SHOW_DRAFTS=true`) mit
HTTP Basic Auth und `X-Robots-Tag: noindex, nofollow`, umgesetzt über die
Traefik-Middleware-Kette in `docker-compose.yml` (`TRAEFIK_MIDDLEWARES`,
`STAGING_BASIC_AUTH`, `STAGING_ROBOTS_TAG`). Eigenes Compose-Projekt:

```sh
COMPOSE_PROJECT_NAME=hvm-staging
APP_ENV=staging
TRAEFIK_ROUTER=hvm-staging
TRAEFIK_MIDDLEWARES=hvm-staging-staging@docker,hvm-staging-compress@docker
STAGING_BASIC_AUTH='benutzer:<htpasswd-hash>'
```

Freigabe der Geschäftsführung für Design, Texte, Impressum, Datenschutz und Livegang erfolgt
über `config/freigaben.php` und wird vor der DNS-Umstellung auf Produktion geprüft (MP
Abschnitt 14).

### 1.3 Vor jedem Deployment

- `composer test` (PHPUnit) grün
- `composer lint` (`php -l` und `php bin/lint-dashes.php`) ohne Treffer
- auf Staging zusätzlich: `php bin/check-pii.php --base-url=https://<staging-host>` und
  `php bin/check-headers.php --base-url=https://<staging-host>` ohne Befunde

### 1.4 Netzwerk und TRUSTED_PROXIES

Anfragen laufen Traefik → Nginx (Netz `traefik`) → PHP-FPM (Netz `backend`). Nginx reicht die
Adresse seines Gegenübers, also die Traefik-Adresse im Netz `traefik`, als `REMOTE_ADDR` an
PHP-FPM weiter, `X-Forwarded-For` setzt Traefik. Die Anwendung wertet `X-Forwarded-For` nur aus,
wenn `REMOTE_ADDR` in `TRUSTED_PROXIES` liegt (`docs/architektur.md` Abschnitt 4). Daher gilt:

1. Das Traefik-Netz erhält ein festes Subnetz. Wird es neu angelegt:
   ```sh
   docker network create --subnet 172.30.90.0/24 traefik
   ```
   Besteht es bereits, Subnetz ablesen und unverändert übernehmen:
   ```sh
   docker network inspect traefik -f '{{range .IPAM.Config}}{{.Subnet}}{{end}}'
   ```
2. `TRUSTED_PROXIES` nennt genau dieses Subnetz, zum Beispiel `TRUSTED_PROXIES=172.30.90.0/24`.
   Nicht mehr `172.16.0.0/12`: Dieser Bereich umfasst alle Docker-Standardnetze, jeder Container
   auf dem Host könnte dann eine beliebige Client-IP vortäuschen und damit Rate-Limits sowie
   `ADMIN_IP_ALLOWLIST` umgehen.
3. Das interne Netz `backend` hat ein festes Subnetz (`BACKEND_SUBNET`, Standard
   `172.30.80.0/24`). Staging und Produktion laufen auf demselben Host und brauchen
   unterschiedliche Werte (etwa Staging `172.30.81.0/24`), sonst startet das zweite Projekt
   nicht. Das Backend-Subnetz gehört nicht in `TRUSTED_PROXIES`.
4. Die Subnetze dürfen sich weder untereinander noch mit Netzen des Hosts oder des
   Rechenzentrums überschneiden. Vor dem ersten Start mit `docker network ls` und
   `docker network inspect` prüfen.
5. Kontrolle nach dem Deployment: Ein Login-Fehlversuch im Admin muss im Log mit der
   tatsächlichen Client-IP gezählt werden, nicht mit einer Adresse aus `172.30.90.0/24`.

Webhook an n8n: In Produktion ist nur `https://` zulässig. Liegt n8n im selben Docker-Host und
wird über einen Dienstnamen ohne Punkt (etwa `http://n8n:5678/...`) oder eine private IP-Adresse
angesprochen, erlaubt `N8N_WEBHOOK_ALLOW_HTTP_INTERNAL=true` dort auch `http://`. Eine unzulässige
Adresse versendet nichts, der Outbox-Eintrag bleibt mit dem Hinweis „Wartet auf Konfiguration:
N8N_WEBHOOK_URL muss in Produktion https verwenden“ zurückgestellt (ohne URL und ohne Leaddaten).

### 1.5 Nacharbeiten: bin/deploy-post.sh

```sh
bin/deploy-post.sh [--ohne-build] [--url=https://www.muellerhv.de]
```

1. `php bin/migrate.php` (Datenbank aus `DB_*`)
2. Twig-Cache leeren (`storage/cache/twig`)
3. Build wie `composer build` (entfällt mit `--ohne-build`, Docker siehe Abschnitt 1.1)
4. Rauchtest `GET <url>/health`: bricht mit Exitcode 1 ab, wenn die Datenbank laut Antwort nicht
   erreichbar ist

Ohne Docker (zum Beispiel lokale oder klassische Installation) läuft das Skript ohne Optionen
und baut die Assets mit. Der Asset-Build ist atomar: ein laufender Server liefert bis zum Tausch
die bisherigen Dateien aus.

### 1.6 Auslieferung statischer Dateien

- `gzip_static on` in `docker/nginx/default.conf`: Zu CSS, JS, JSON und SVG (Build,
  `public/assets/img`, Suchindex) erzeugt der Build `.gz`-Dateien, Nginx liefert sie ohne
  Kompression je Anfrage aus. `.br` entsteht nur, wenn die PHP-Erweiterung `brotli` geladen ist
  (derzeit nicht), und würde zusätzlich das Nginx-Modul `brotli_static` voraussetzen.
- Dynamische Antworten (HTML, `sitemap.xml`, `llms.txt` als `text/markdown`, JSON) komprimiert
  Nginx über `gzip_types`, Traefik zusätzlich über die Middleware `compress`.
- Cache-Control: `/assets/build/` ein Jahr `immutable` (Hash im Namen), `/assets/img/` 30 Tage
  mit `stale-while-revalidate`, `/og/` ein Tag mit `stale-while-revalidate`, übrige `/assets/`
  sieben Tage.
- `/.well-known/security.txt` erzeugt die Anwendung (RFC 9116, Kontakt `info@muellerhv.de`,
  `Expires` knapp ein Jahr ab Anfrage, `Preferred-Languages: de`, `Canonical`). Statische Dateien
  unter `/.well-known/` (etwa ACME) haben Vorrang.

### 1.7 Admin-Konten und Wiederherstellungscodes

- `php bin/admin-user.php create <email>` legt ein Konto an und gibt TOTP-Geheimnis und zehn
  Wiederherstellungscodes einmalig aus.
- `php bin/admin-user.php recovery-codes <email>` erzeugt zehn neue Codes, alle bisherigen
  werden ungültig. `list` zeigt die Zahl unbenutzter Codes je Konto.
- Ein Code ersetzt bei der Anmeldung einmalig den TOTP-Code (Format `XXXXX-XXXXX`, Groß- und
  Kleinschreibung sowie Leerzeichen egal). Gespeichert ist nur ein HMAC (Schlüssel aus
  `APP_KEY`), der Verbrauch steht mit Zeitpunkt und IP-Hash in `admin_recovery_codes` und im Log
  (`Admin-Anmeldung mit Wiederherstellungscode`). Nach der Anmeldung zeigt der Admin-Bereich die
  Zahl der verbleibenden Codes, ab drei oder weniger mit Hinweis auf Neuerzeugung.
- Codes offline verwahren (zum Beispiel Passwort-Tresor der Geschäftsführung), nie per E-Mail
  oder Chat. Ein Wechsel des `APP_KEY` macht TOTP-Geheimnisse und Codes unbrauchbar.

## 2. Rollback

1. Images sind über `HVM_TAG` versioniert. Der vorherige Tag muss lokal oder in einer Registry
   vorliegen, um zurückrollen zu können.
2. Anwendung zurückrollen:
   ```sh
   HVM_TAG=<vorheriger-tag> docker compose up -d
   ```
3. Datenbankmigrationen sind ausschließlich vorwärtsgerichtet. Ein Rollback der Anwendung auf
   einen älteren Stand rollt keine Migration zurück. Zwei Fälle:
   - Das neue Release hat das Schema nicht oder nur additiv verändert (neue Spalten, neue
     Tabellen): das alte Image läuft gegen das neue Schema weiter, sobald die Migrationen
     abwärtsverträglich angelegt sind (Spalten zuerst ergänzen, später entfernen, siehe
     `docs/entscheidungen.md`).
   - Das neue Release hat das Schema inkompatibel verändert: Datenbank aus der vor dem
     Deployment erstellten Sicherung wiederherstellen (Abschnitt 3.4), danach das alte Image
     starten. Datenverlust seit der Sicherung ist damit verbunden und vorher abzuwägen.
4. Nach einem Rollback: Rauchtest laut Abschnitt 4 wiederholen.

## 3. Backup und Wiederherstellung

### 3.1 Werkzeuge

- `bin/backup-db.sh`: Sicherung per `mariadb-dump` (`--single-transaction`, ohne globale
  Sperre), Kompression (`gzip -9`), Verschlüsselung, Rotation.
- `bin/restore-db.sh`: Entschlüsselung, Einspielen in eine Zieldatenbank, mit
  Sicherheitsabfrage vor dem Überschreiben.
- `docker/backup/`: Docker-Variante als eigener Dienst mit Cron (Profil `backup`), Alternative
  zu einem Cronjob auf dem Host.

### 3.2 Verschlüsselung

Standardmethode `age` (`BACKUP_METHOD=age`): asymmetrische, moderne authentifizierte
Verschlüsselung (X25519, ChaCha20-Poly1305). Die Sicherung wird mit dem öffentlichen
Schlüssel des Backup-Empfängers verschlüsselt (`BACKUP_AGE_RECIPIENT`), das Sicherungsskript
selbst benötigt keinen privaten Schlüssel. Für die Wiederherstellung wird der private
Schlüssel benötigt (`BACKUP_AGE_IDENTITY`), der ausschließlich außerhalb des Repositories und
außerhalb des Produktivservers sicher verwahrt wird (zum Beispiel Passwort-Tresor der
Geschäftsführung).

Alternative `openssl` (`BACKUP_METHOD=openssl`): AES-256-CBC mit PBKDF2 und Salt, Schlüssel in
einer Datei außerhalb des Repositories (`BACKUP_OPENSSL_KEYFILE`). Einsatz nur, wenn `age` auf
dem Zielsystem nicht verfügbar ist.

Keine Zugangsdaten oder Schlüssel in diesem Dokument, im Repository oder in Logs.

### 3.3 Aufbewahrung

Rotation über `BACKUP_KEEP` (Standard 14 Sicherungen je Datenbank), ältere Sicherungen werden
von `bin/backup-db.sh` automatisch entfernt. Empfehlung für den Produktivbetrieb: zusätzlich
eine Kopie außerhalb des Servers vorhalten (zum Beispiel Object Storage oder Offsite-Backup),
placeholder('Aufbewahrungsort außerhalb des Servers festlegen').

### 3.4 Wiederherstellung

```sh
bin/restore-db.sh --file=/pfad/zur/sicherung.sql.gz.age --database=<zieldatenbank>
```

Das Skript fragt vor dem Einspielen den Namen der Zieldatenbank zur Bestätigung ab
(`--yes` überspringt die Abfrage, nur für automatisierte Wiederherstellungstests). Eine
bestehende Zieldatenbank wird überschrieben.

**Getestet am 23.09.2026** (lokale Entwicklungsumgebung, MariaDB 10.11): Sicherung von
`hvm_dev` erstellt, mit `age` verschlüsselt, entschlüsselt und in `hvm_test` wiederhergestellt.
Ergebnis: Wiederherstellung erfolgreich, 10 Tabellen vorhanden (`admin_login_attempts`,
`admin_users`, `contact_requests`, `job_applications`, `lead_events`, `leads`, `outbox`,
`price_tiers`, `rate_limits`, `schema_migrations`). Kein Datenvergleich einzelner Zeilen
durchgeführt, da die lokale Entwicklungsdatenbank keine echten Testdaten enthält.

### 3.5 Docker-Variante

```sh
docker compose --profile backup up -d backup
```

Der Dienst führt `bin/backup-db.sh` täglich um 03:15 UTC über Cron im Container aus
(`docker/backup/crontab`), Sicherungen liegen im Volume `backup_data`. Alternative: Cronjob
auf dem Host, der `docker compose run --rm backup /app/bin/backup-db.sh` aufruft, wenn ein
dauerhaft laufender Backup-Container nicht gewünscht ist.

## 4. Monitoring

- **Healthcheck**: `GET /health` liefert `{"status":"ok","datenbank":"ja"}` bzw.
  `{"status":"eingeschraenkt","datenbank":"nein"}`, ohne Versions- oder Pfadangaben,
  `Cache-Control: no-store`. Der Dienst `nginx` nutzt den Endpunkt als Docker-Healthcheck
  (`docker-compose.yml`) und prüft damit Nginx und PHP-FPM. Die Antwort ist auch bei
  Datenbankausfall 200: Traefik nimmt ungesunde Container aus dem Routing, die Inhaltsseiten
  funktionieren aber ohne Datenbank. Externes Monitoring wertet daher das Feld `datenbank` aus.
  Der Verbindungsaufbau bricht nach zwei Sekunden ab.
- **Logs**: `storage/logs` (Volume `storage_logs`), Format und Redaktion laut
  `docs/architektur.md` (`Log::redact()` entfernt E-Mail-Adressen und Telefonnummern vor dem
  Schreiben). Log-Rotation über den Host oder einen Log-Dienst,
  placeholder('Log-Aufbewahrung und -Rotation festlegen, z. B. logrotate oder externer
  Log-Dienst').
- **404-Monitoring der ersten vier Wochen** (MP Phase 7): Serverzugriffe auf Status 404
  täglich auswerten (Nginx-Access-Log oder ein externer Dienst), insbesondere für Adressen aus
  `docs/redirects.md`, die keine Weiterleitung erhalten haben. Fehlende Weiterleitungen
  zeitnah ergänzen. Nach vier Wochen auf stichprobenartige Kontrolle reduzieren.
- **PII- und Header-Check**: `bin/check-pii.php` und `bin/check-headers.php` vor jedem
  Deployment auf Staging, danach empfohlen als wiederkehrende Prüfung auf Produktion
  (Lehre aus der Altseite, MP Abschnitt 10).
- **Backup-Überwachung**: Erfolg oder Fehlschlag von `bin/backup-db.sh` auswerten (Exitcode,
  Log-Ausgabe des Backup-Dienstes). placeholder('Benachrichtigung bei fehlgeschlagener
  Sicherung einrichten, z. B. E-Mail oder Monitoring-Dienst').

- **Abhängigkeiten**: Die CI prüft mit `composer audit` und `npm audit --omit=dev` in einem
  eigenen Job. Hinweise mit Schweregrad low erscheinen als Warnung, ab medium schlägt der Job
  fehl. Dependabot (`.github/dependabot.yml`) schlägt wöchentlich (montags) Aktualisierungen für
  Composer, npm und GitHub Actions vor; `@playwright/test` nur als Patch, da Chromium lokal auf
  1.56.1 abgestimmt ist.

## 5. Livegang-Checkliste (MP Phase 7)

- [ ] Datenleck der Altseite nachweislich geschlossen (`docs/phase0.md`)
- [ ] Freigabe der Geschäftsführung für Design, Texte, Impressum, Datenschutz und Livegang
      liegt dokumentiert vor (`config/freigaben.php`)
- [ ] Alle Seiten aus `docs/architektur.md` beziehungsweise MP Abschnitt 5 vorhanden und über
      Staging geprüft
- [ ] Alle alten URLs aus `docs/redirects.md` leiten korrekt um (Status geprüft, nicht nur
      dokumentiert)
- [ ] `composer test` grün, `composer lint` ohne Treffer
- [ ] `php bin/check-pii.php` und `php bin/check-headers.php` gegen Staging ohne Befunde
- [ ] Lighthouse mindestens 95 in allen Kategorien, Core Web Vitals im Zielbereich (LCP unter
      2,0 s, CLS unter 0,05, INP unter 200 ms mobil)
- [ ] WCAG 2.2 AA ohne kritische Befunde (axe oder vergleichbares Werkzeug)
- [ ] Strukturierte Daten (`Organization`, `LocalBusiness`, `Service`, `FAQPage`,
      `BreadcrumbList`) validiert
- [ ] Datenbanksicherung unmittelbar vor der DNS-Umstellung erstellt und Wiederherstellung
      getestet (Abschnitt 3.4)
- [ ] Security-Header vollständig (CSP ohne `unsafe-inline`, HSTS, X-Content-Type-Options,
      Referrer-Policy, Permissions-Policy)
- [ ] Altseite als statisches Archiv gesichert, bevor sie offline geht (Grundlage:
      `bin/legacy-mirror.sh`, bereinigt über `bin/legacy-scrub.php`)
- [ ] DNS-Umstellung erst nach ausdrücklicher Freigabe der Geschäftsführung
- [ ] Monitoring der 404-Fehler für die ersten vier Wochen eingerichtet (Abschnitt 4)
- [ ] Keine Gedankenstriche in deutschen Texten, keine unbelegten Aussagen, keine erfundenen
      Daten, alle Platzhalter aufgelöst oder bewusst freigegeben dokumentiert

## 6. Abnahmekriterien (MP Abschnitt 14)

- [ ] Alle Seiten aus MP Abschnitt 5 vorhanden, alle alten URLs leiten korrekt um
- [ ] Lead wird gespeichert, E-Mail und Webhook kommen an, Status im Admin änderbar, Import der
      Altleads protokolliert
- [ ] Keine personenbezogenen Daten in öffentlichem HTML, Logs oder Repository
- [ ] WCAG 2.2 AA ohne kritische Befunde, Lighthouse mindestens 95
- [ ] Keine Gedankenstriche in deutschen Texten, keine unbelegten Aussagen, keine erfundenen
      Daten, alle Platzhalter in `docs/bildinventar.md` und den Fachdokumenten aufgelöst oder
      bewusst freigegeben
- [ ] Freigabe der Geschäftsführung für Design, Texte, Impressum, Datenschutz und Livegang
      liegt dokumentiert vor

## Offene Punkte

- erledigt (24.09.2026): Staging-Subdomain `neu.muellerhv.de`
- placeholder('Externes Monitoring für /health (Feld datenbank) einrichten')
- placeholder('Log-Aufbewahrung und -Rotation festlegen')
- placeholder('Aufbewahrungsort der Sicherungen außerhalb des Servers festlegen')
- placeholder('Benachrichtigung bei fehlgeschlagener Sicherung einrichten')
