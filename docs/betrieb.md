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
4. Migrationen ausführen:
   ```sh
   docker compose run --rm php php bin/migrate.php
   ```
   Migrationen sind ausschließlich vorwärtsgerichtet (`docs/entscheidungen.md`). Ein Rollback
   der Anwendung setzt keine Migration zurück, siehe Abschnitt 2.
5. Asset-Build ist Teil des Image-Baus (`php bin/build-assets.php` im Dockerfile). Ein
   separater Schritt ist im Normalbetrieb nicht nötig.
6. Worker (`bin/worker.php`, Outbox für Mail und Webhook) mit eigenem Profil starten, sobald
   die Datei vorhanden ist:
   ```sh
   docker compose --profile worker up -d worker
   ```
7. Nach dem Deployment: Rauchtest laut Abschnitt 4 (Healthcheck, Stichproben-Seiten,
   Formularstrecke im Staging).

### 1.2 Staging

Staging läuft unter placeholder('Staging-Subdomain festlegen, z. B. neu.muellerhv.de') mit
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

- **Healthcheck**: Der Dienst `nginx` prüft `/robots.txt` (`docker-compose.yml`,
  `healthcheck`), das genügt für „Container antwortet“. Ein fachlicher Healthcheck-Endpunkt
  (zum Beispiel `/gesund/` mit Datenbankprüfung) ist im Code noch nicht vorhanden,
  placeholder('Healthcheck-Endpunkt mit Fachteam abstimmen und implementieren').
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

- placeholder('Staging-Subdomain festlegen')
- placeholder('Healthcheck-Endpunkt mit Fachteam abstimmen und implementieren')
- placeholder('Log-Aufbewahrung und -Rotation festlegen')
- placeholder('Aufbewahrungsort der Sicherungen außerhalb des Servers festlegen')
- placeholder('Benachrichtigung bei fehlgeschlagener Sicherung einrichten')
