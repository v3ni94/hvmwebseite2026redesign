# Deployment per SFTP (Webhosting ohne Kommandozeile)

Stand: 24.09.2026. Gilt für die Staging-Umgebung `neu.muellerhv.de`, solange dort nur SFTP-Zugang
besteht (vermutlich IONOS Webhosting mit Apache und PHP-FPM, keine Kommandozeile, kein Docker,
Datenbank über das Hosting-Panel). Der Docker-Betrieb hinter Traefik bleibt in `docs/betrieb.md`
beschrieben. Enthält keine Zugangsdaten und keine Schlüssel.

Die Menübezeichnungen im Hosting-Panel ändern sich gelegentlich. Die folgenden Schritte nennen
daher die Funktion, nicht einen festen Menüpfad. Wo ein Pfad genannt ist, ist er vor Ort zu prüfen.

## 1. Überblick

| Baustein | Lösung ohne Kommandozeile |
|---|---|
| Abhängigkeiten, Assets, OG-Bilder, Suchindex | im Release-Paket enthalten (`bin/build-release.sh`) |
| Konfiguration | `.env` im Paket, mit frischem `APP_KEY` und `SETUP_TOKEN` |
| Schöne URLs, Sperren, Cache-Header | `public/.htaccess` (Apache 2.4, alle Module mit `IfModule` abgesichert) |
| Document Root nicht änderbar | `.htaccess` im Paketordner leitet nach `public/` um und sperrt alle anderen Ordner |
| Diagnose, Migrationen, erster Admin | Web-Einrichtung unter `/_einrichtung/` (Token aus `.env`) |
| Mail und Webhook ohne Worker | `OUTBOX_MODE=inline`: Versand direkt nach der Antwort |
| Passwortschutz Staging | `STAGING_BASIC_AUTH` in `.env`, Prüfung durch die Anwendung |

## 2. Release-Paket erzeugen (Entwicklungsrechner)

```sh
bin/build-release.sh                     # Ausgabe build/release und build/release.zip
bin/build-release.sh /pfad/zum/ordner    # anderer Ausgabeordner, ZIP daneben
```

Das Skript kopiert den Quellcode ohne Tests, Doku, `.github`, Node, Legacy und Playwright-Dateien,
installiert die Abhängigkeiten ohne Entwicklungspakete (`composer install --no-dev
--optimize-autoloader --classmap-authoritative`), führt `composer build` aus, legt leere
`storage`-Ordner mit `Require all denied` an, erzeugt die `.env` (`APP_ENV=staging`,
`APP_URL=https://neu.muellerhv.de`, `SHOW_DRAFTS=true`, `OUTBOX_MODE=inline`, frischer `APP_KEY`
und `SETUP_TOKEN`, Datenbankfelder leer) und schreibt `LIESMICH-SFTP.txt`. Zum Schluss prüft es
das Paket (kein `.git`, keine PDF-Dateien, `vendor` und `public/assets/build` vorhanden) und
erstellt das ZIP.

Wichtig: Die `.env` im Paket enthält Schlüssel. Paket und ZIP nicht ins Repository legen
(`build/` steht in `.gitignore`), nicht per E-Mail versenden und nach dem Hochladen lokal löschen.
Jeder Lauf erzeugt neue Schlüssel. Bei einem Update einer bereits eingerichteten Umgebung die
`.env` des Servers behalten und nicht mit der neuen überschreiben, sonst werden TOTP-Geheimnisse,
Wiederherstellungscodes und verschlüsselte Uploads unlesbar.

## 3. Hosting-Panel vorbereiten

1. **PHP-Version:** für die Subdomain `neu.muellerhv.de` PHP 8.3 oder neuer wählen. Benötigte
   Erweiterungen: pdo_mysql, mbstring, sodium, json, ctype, xmlwriter, fileinfo, openssl,
   optional curl (nur für den n8n-Webhook). Die Diagnose zeigt, was fehlt.
2. **Document Root:** das Zielverzeichnis der Subdomain auf den Ordner `public` des hochgeladenen
   Pakets setzen, z. B. `/neu.muellerhv.de/public`. Alternative, falls das Panel das nicht zulässt:
   Zielverzeichnis auf den Paketordner selbst. Dann übernimmt die `.htaccess` im Paketordner, leitet
   intern nach `public/` um und sperrt `src`, `config`, `vendor`, `storage`, `templates`,
   `content`, `migrations`, `bin` sowie `.env` und alle versteckten Dateien (Antwort 403 bzw. 404).
3. **Datenbank:** eine MySQL- bzw. MariaDB-Datenbank anlegen. Host, Port, Datenbankname,
   Benutzer und Passwort notieren. phpMyAdmin wird für die Einrichtung nicht benötigt.
4. **HTTPS:** Zertifikat für die Subdomain aktivieren (im Panel meist unter Domains bzw. SSL).

## 4. .env ausfüllen

Die `.env` aus dem Paket lokal mit einem Texteditor (UTF-8) öffnen und ergänzen:

- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` aus Schritt 3.3.
- `APP_URL` prüfen (`https://neu.muellerhv.de`).
- `MAIL_HOST`, `MAIL_PORT`, `MAIL_USER`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM` und
  `LEAD_NOTIFY_TO` eintragen, sobald das Versandpostfach feststeht. Bis dahin bleiben
  Benachrichtigungen in der Warteschlange und werden nachgeholt.
- `APP_KEY` und `SETUP_TOKEN` nicht verändern.

Die Datei darf auch später jederzeit ersetzt werden, die Anwendung liest sie bei jeder Anfrage.

## 5. Hochladen per SFTP

1. Im SFTP-Programm die Anzeige versteckter Dateien einschalten. `.env` und die
   `.htaccess`-Dateien beginnen mit einem Punkt und werden sonst weder angezeigt noch übertragen.
2. Den gesamten Inhalt des Paketordners in das Zielverzeichnis hochladen (ZIP vorher lokal
   entpacken, falls das Hosting kein Entpacken anbietet).
3. Schreibrechte: Ordner `storage` und alle Unterordner auf 755 setzen; kann der Webserver damit
   nicht schreiben (Diagnose meldet „storage/... beschreibbar“ als Fehler), 775 verwenden. `.env`
   auf 640 bzw. 600 setzen, sofern das Hosting das erlaubt.

## 6. Einrichtung im Browser

1. `https://neu.muellerhv.de/_einrichtung/` aufrufen und den `SETUP_TOKEN` aus der `.env`
   eingeben. Nach fünf falschen Versuchen je IP-Adresse ist die Anmeldung 15 Minuten gesperrt.
2. **Diagnose** prüfen: Einträge mit „Fehler“ beheben (Lösungshinweis steht jeweils darunter),
   „Hinweis“ ist optional. Es werden keine Geheimnisse angezeigt, nur ob Werte gesetzt und gültig
   sind.
3. **Migrationen ausführen.** Ein zweiter Aufruf meldet „Keine offenen Migrationen“.
4. **Ersten Admin anlegen:** E-Mail-Adresse und Passwort (mindestens 12 Zeichen, ohne
   Bestandteile der E-Mail-Adresse). Die Einrichtung legt nur das erste Konto an.
5. **TOTP einrichten:** Das angezeigte Geheimnis in einer Authenticator-App als zeitbasiertes
   Konto (6 Stellen, 30 Sekunden) hinterlegen. Alternativ die otpauth-URI in der App verwenden.
6. **Wiederherstellungscodes** (zehn Stück, je einmal verwendbar) offline sicher ablegen, z. B.
   ausgedruckt im Tresor oder im Passwortmanager. Geheimnis und Codes werden nur einmal
   angezeigt und nicht gespeichert.
7. Admin-Anmeldung unter `/admin/login/` in einem zweiten Browserfenster testen.
8. **Einrichtung abschließen:** schreibt `storage/setup.lock`. Danach antwortet
   `/_einrichtung/` mit 404. Anschließend `SETUP_TOKEN` aus der `.env` entfernen und die Datei
   erneut hochladen. Für eine erneute Einrichtung `storage/setup.lock` per SFTP löschen und einen
   neuen Token (mindestens 32 zufällige Zeichen) setzen.

Die Einrichtung ist in jeder Umgebung nur erreichbar, wenn `SETUP_TOKEN` mindestens 32 Zeichen
hat und `storage/setup.lock` fehlt, auch bei `APP_ENV=production`. Sie erscheint nie in
Sitemap, `robots.txt` oder `llms.txt`.

## 7. Staging-Schutz (optional, empfohlen)

1. Auf der Einrichtungsseite unter „Hash-Hilfe für den Staging-Schutz“ Benutzername und Passwort
   (mindestens 12 Zeichen) eingeben. Die Seite erzeugt eine Zeile wie
   `STAGING_BASIC_AUTH='benutzer:$2y$12$...'`.
2. Diese Zeile unverändert (mit den einfachen Anführungszeichen) in die `.env` übernehmen und die
   Datei hochladen. Ist die Einrichtung bereits abgeschlossen, kann der Hash auch mit jedem
   anderen Werkzeug erzeugt werden, das bcrypt-Hashes im Format `$2y$` liefert.
3. Ab sofort fragt der Browser auf allen Seiten nach Benutzer und Passwort (HTTP Basic Auth,
   Antwort 401 ohne Anmeldung). `/health` bleibt für das Monitoring frei. `X-Robots-Tag: noindex,
   nofollow` bleibt auf Staging unabhängig davon gesetzt.

Technik: Der Schutz greift nur bei `APP_ENV=staging`. Unter PHP-FPM bzw. CGI reicht Apache den
Authorization-Header nicht von selbst an PHP weiter; `public/.htaccess` setzt ihn daher per
`RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`. Ein Klartextwert statt eines
Hashes sperrt die Seite (sicher statt offen). htpasswd-Hashes im Format `$apr1$` prüft die
Anwendung nicht, sie sind für Traefik im Docker-Betrieb gedacht (`docs/betrieb.md` 1.2).

## 8. Mail und Webhook ohne Cronjob

`OUTBOX_MODE=inline` (im Paket gesetzt): Nach jedem abgesendeten Formular verarbeitet die
Anwendung nach dem Senden der Antwort bis zu fünf fällige Einträge der Warteschlange (Mail an
`LEAD_NOTIFY_TO`, Webhook an n8n). Unter PHP-FPM wird die Verbindung zum Browser vorher
geschlossen (`fastcgi_finish_request`), Besucher warten also nicht auf den Mailserver. Einträge,
die wegen eines Fehlers erneut geplant sind, werden bei Seitenaufrufen höchstens alle fünf
Minuten nachgeholt. Eine Dateisperre in `storage/cache` und die Sperre in der Tabelle `outbox`
verhindern Doppelversand. Fehler landen nur im Log (`storage/logs/app.log`), nie in der Antwort.

Steht im Hosting ein Cronjob zur Verfügung, ist ein regelmäßiger Lauf zuverlässiger:
`php bin/worker.php --once` alle fünf Minuten (Pfad zum PHP-Binary und Arbeitsverzeichnis laut
Hosting-Dokumentation) und `OUTBOX_MODE=worker` setzen. Beide Varianten dürfen auch gleichzeitig
laufen.

## 9. Abnahme auf Staging

1. Startseite, einige Unterseiten, `/sitemap.xml` und `/health` aufrufen (`/health` meldet
   `"datenbank":"ja"`).
2. Admin-Anmeldung mit Passwort und Code aus der App.
3. Angebotsformular unter `/angebot/` mit fiktiven Daten absenden, Eingang der Benachrichtigung
   bei `LEAD_NOTIFY_TO` prüfen, Lead im Admin-Bereich prüfen und danach löschen bzw. als Test
   kennzeichnen.
4. Stichprobe Sperren: `https://neu.muellerhv.de/.env` und `/storage/logs/app.log` müssen 403
   oder 404 liefern.

## 10. Fehlerbilder

| Bild | Ursache und Lösung |
|---|---|
| 503 „Die Seite ist vorübergehend nicht erreichbar“ | Startfehler der Anwendung: `.env` fehlt (versteckte Datei nicht hochgeladen), `APP_KEY` ungültig, `vendor` unvollständig. Details stehen im PHP-Fehlerlog (Meldung beginnt mit „HVM Startfehler“). |
| 500 direkt nach dem Hochladen, auch für statische Dateien | Meist eine vom Hosting nicht erlaubte Direktive in einer `.htaccess`. In `public/.htaccess` (bzw. in der Wurzel-`.htaccess`) die Zeile mit `Options` testweise mit `#` auskommentieren. |
| 500 nur für Seiten | Anwendungsfehler: `storage/logs/app.log` per SFTP herunterladen und die letzten Zeilen prüfen. Häufig fehlen Schreibrechte für `storage` oder eine PHP-Erweiterung (Diagnose). |
| 404 des Webservers für alle Seiten außer der Startseite | `.htaccess` nicht hochgeladen oder mod_rewrite nicht verfügbar. |
| Formular meldet Erfolg, aber keine Mail | `MAIL_*` bzw. `LEAD_NOTIFY_TO` fehlen oder sind falsch. Die Einträge bleiben in der Warteschlange und werden nach Korrektur beim nächsten Formular bzw. nach spätestens fünf Minuten nachgeholt. Hinweise im Log. |
| Staging fragt immer wieder nach dem Passwort | Authorization-Header kommt nicht an (RewriteRule in `public/.htaccess` fehlt) oder der Hash in `STAGING_BASIC_AUTH` ist beschädigt (Anführungszeichen vergessen). |
| `/_einrichtung/` liefert 404 | `SETUP_TOKEN` fehlt oder ist kürzer als 32 Zeichen, oder `storage/setup.lock` existiert. |

**PHP-Fehlerlog:** Wo das Hosting PHP-Fehler protokolliert, ist je nach Tarif unterschiedlich. Üblich
sind ein Bereich für Logs bzw. PHP-Einstellungen im Hosting-Panel oder eine Protokolldatei im
Webspace (z. B. in einem Ordner `logs` außerhalb des Zielverzeichnisses). Im Zweifel die
Hilfeseiten des Hosters zu „PHP Fehlerprotokoll“ bzw. „error_log“ heranziehen. Die Anwendung
schreibt ihre eigenen Meldungen unabhängig davon nach `storage/logs/app.log` (ohne
personenbezogene Daten).

## 11. Updates

1. Neues Paket mit `bin/build-release.sh` erzeugen.
2. Alles außer `.env` und `storage/` hochladen (vorher den Ordner `vendor` auf dem Server durch den
   neuen ersetzen, damit keine veralteten Dateien übrig bleiben).
3. Neue Migrationen: `SETUP_TOKEN` setzen, `storage/setup.lock` löschen, unter `/_einrichtung/`
   „Migrationen ausführen“ und wieder abschließen. Vorher eine Sicherung der Datenbank über
   phpMyAdmin (Export) erstellen.
4. Den Twig-Cache `storage/cache/twig` leeren (Inhalt löschen), damit geänderte Templates sofort
   greifen.
