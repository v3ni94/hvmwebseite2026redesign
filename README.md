# muellerhv.de

Webseite der Hausverwaltung Müller GmbH. Eigene PHP-Anwendung ohne Framework: PHP 8.3, Twig, MariaDB, Nginx und PHP-FPM in Docker hinter Traefik.

Verbindliche Grundlagen stehen in `docs/`:

- `docs/architektur.md`: Architektur und Konventionen (verbindlich)
- `docs/entscheidungen.md`: Paket- und Architekturentscheidungen mit Begründung
- `docs/redirects.md`: Weiterleitungen der Altseite mit Verifikationsstatus
- `docs/bestandsaufnahme-muellerhv-de.md`: Ist-Zustand der Altseite
- `docs/phase0.md`: Voraussetzung Datenleck Altseite
- `docs/quellen/`: Auftragsgrundlagen (Masterprompt)
- `docs/betrieb.md`: Deployment, Rollback, Backup, Wiederherstellung, Monitoring,
  Livegang-Checkliste (verbindlich, ergänzt die Abschnitte Deployment und Betrieb unten)
- `docs/bildinventar.md`: Bildinventar mit Lizenzstatus

## Voraussetzungen lokal

- PHP 8.3 oder 8.4 mit den Erweiterungen pdo_mysql, mbstring, intl, sodium, gd
- Composer 2
- MariaDB 10.11 (für Migrationen und Integrationstests)
- Node 22 nur für Prüfwerkzeuge (Playwright, axe)

## Einrichtung

```sh
composer install
cp .env.example .env          # APP_ENV=development, Zugangsdaten eintragen
php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'   # Wert für APP_KEY
```

Datenbanken und Benutzer anlegen (Beispiel, Passwort selbst wählen):

```sql
CREATE DATABASE hvm_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE hvm_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'hvm'@'localhost' IDENTIFIED BY '<passwort>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES ON hvm_dev.* TO 'hvm'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES ON hvm_test.* TO 'hvm'@'localhost';
```

Dann:

```sh
composer build                 # CSS und JS nach public/assets/build/
composer migrate               # Migrationen auf DB_NAME
php bin/migrate.php --database=test
php -S 127.0.0.1:8081 -t public public/index.php
```

Die Seite läuft unter http://127.0.0.1:8081/. Der eingebaute Server liefert vorhandene Dateien direkt aus, alles andere geht an den Front Controller.

## Befehle

| Befehl | Zweck |
|---|---|
| `composer build` | Asset-Build (`bin/build-assets.php`), schreibt `public/assets/build/manifest.json` |
| `composer test` | PHPUnit (`tests/Unit`, später `tests/Integration`) |
| `composer lint` | `php -l` über `src`, `bin`, `config` und Gedankenstrich-Linter |
| `composer migrate` | Migrationen ausführen (`bin/migrate.php`, Option `--database=test`, `--status`) |
| `php bin/lint-dashes.php` | Texte auf Gedankenstriche prüfen (Exitcode 1 bei Treffern) |

## Verzeichnisse

| Verzeichnis | Inhalt |
|---|---|
| `public/` | Front Controller `index.php`, statische Dateien, Build-Ausgabe (`assets/build`, nicht im Repository) |
| `src/` | Namespace `Hvm\`: `Http` (Kernel, Router, Middleware), `Controller`, `View` (Twig), `Support` (Env, Config, Db, Log, Clock, Uuid, Container) |
| `config/` | Stammdaten und Einstellungen als PHP-Arrays: `app`, `unternehmen`, `kennzahlen`, `seiten`, `routes`, `navigation`, `redirects`, `staedte`, `freigaben`, `kundenstimmen` |
| `templates/` | Twig: `layouts`, `partials`, `components`, `pages` |
| `resources/` | CSS- und JS-Quellen für den Build |
| `content/` | Wissensartikel (Markdown) und FAQ (YAML) |
| `migrations/` | SQL-Migrationen `NNNN_name.sql`, nie nachträglich ändern |
| `bin/` | Kommandozeilenwerkzeuge |
| `tests/` | PHPUnit und Playwright |
| `docker/` | Dockerfile (Ziele `app` und `web`), PHP- und Nginx-Konfiguration |
| `storage/` | Logs, Twig-Cache, Uploads (nicht im Repository) |

## Konfiguration

Alle Variablen mit Erläuterung in `.env.example`. Fehlt `APP_ENV` oder ist der Wert unbekannt, gilt `production`: keine Fehlerdetails, HSTS, kein Styleguide. Außerhalb der Produktion senden alle Seiten `X-Robots-Tag: noindex, nofollow`, robots.txt sperrt alles.

Fachliche Stammdaten liegen in `config/unternehmen.php` und `config/kennzahlen.php`. Werte `null` (Telefon, Notfallnummer, USt-IdNr., Portal) erscheinen als sichtbarer Platzhalter und werden nie erfunden.

## Deployment (Docker Compose hinter Traefik)

Voraussetzung auf dem Server: Traefik mit externem Netzwerk (Standard `traefik`), Entrypoint `websecure`, Zertifikatsresolver `letsencrypt`. Abweichende Namen über `TRAEFIK_*` in `.env`.

```sh
cp .env.example .env            # APP_ENV=production, APP_URL, APP_HOST, DB_*, DB_ROOT_PASSWORD, MAIL_* usw.
docker compose build
docker compose up -d
docker compose run --rm php php bin/migrate.php
```

Staging als eigenes Compose-Projekt mit eigener `.env`: `COMPOSE_PROJECT_NAME=hvm-staging`, `APP_ENV=staging`, `TRAEFIK_ROUTER=hvm-staging`, `TRAEFIK_MIDDLEWARES=hvm-staging-staging@docker,hvm-staging-compress@docker` und `STAGING_BASIC_AUTH` im htpasswd-Format in einfachen Anführungszeichen. Die Staging-Middleware setzt Basic Auth und `X-Robots-Tag`.

Der Worker (`bin/worker.php`, Outbox für Mail und Webhook) wird mit `docker compose --profile worker up -d worker` gestartet, sobald die Datei vorhanden ist.

Vor jedem Deployment: `composer test`, `composer lint`, auf Staging `php bin/check-pii.php --base-url=...` und `php bin/check-headers.php --base-url=...`.

Ausführliche Anleitung mit Staging-Betrieb, Rauchtest und Livegang-Checkliste: `docs/betrieb.md`.

## Rollback

Images werden mit `HVM_TAG` versioniert (z. B. Git-Commit oder Datum).

1. Vor dem Deployment Datenbanksicherung erstellen (`mariadb-dump` im Dienst `db`, verschlüsselt ablegen).
2. Neues Image bauen und starten: `HVM_TAG=<neu> docker compose build && HVM_TAG=<neu> docker compose up -d`.
3. Rollback: `HVM_TAG=<vorher> docker compose up -d`. Das vorherige Image muss lokal oder in einer Registry vorliegen.
4. Migrationen sind vorwärtsgerichtet. Hat das neue Release das Schema verändert und ist das alte Release damit nicht verträglich, Datenbank aus der Sicherung wiederherstellen. Migrationen daher möglichst abwärtsverträglich anlegen (Spalten zuerst ergänzen, später entfernen).

## Backup und Wiederherstellung

`bin/backup-db.sh` sichert per `mariadb-dump` (`--single-transaction`), komprimiert und verschlüsselt mit `age` (Standard, Begründung und Alternative `openssl` in `docs/betrieb.md`). `bin/restore-db.sh` entschlüsselt und spielt eine Sicherung mit Sicherheitsabfrage in eine Zieldatenbank ein. Docker-Variante als eigener Dienst mit Cron: `docker compose --profile backup up -d backup`. Details, Umgebungsvariablen und das Testergebnis der Wiederherstellung: `docs/betrieb.md`.

## Betrieb

Monitoring (Healthcheck, Logs, 404-Kontrolle der ersten vier Wochen nach Livegang) und die Livegang-Checkliste stehen in `docs/betrieb.md`. Werkzeuge zur Inventur der Altseite (`bin/legacy-mirror.sh`, `bin/legacy-scrub.php`, `bin/legacy-inventory.php`) sind dort ebenfalls beschrieben; `muellerhv.de` ist aus dieser Entwicklungsumgebung gesperrt, die Ausführung erfolgt auf einem Rechner ohne Domainbeschränkung.
