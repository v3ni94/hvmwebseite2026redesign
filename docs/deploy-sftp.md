# Deployment per SFTP (Webhosting ohne Kommandozeile)

Stand: 24.09.2026 (Dateimodus ohne Datenbank). Gilt für die Staging-Umgebung `neu.muellerhv.de`, solange dort nur SFTP-Zugang
besteht (vermutlich IONOS Webhosting mit Apache und PHP-FPM, keine Kommandozeile, kein Docker,
Datenbank wird nicht benötigt). Der Docker-Betrieb hinter Traefik bleibt in `docs/betrieb.md`
beschrieben. Enthält keine Zugangsdaten und keine Schlüssel.

**Betrieb ohne Datenbank (Entscheidung der Geschäftsführung vom 24.09.2026):** Das Paket läuft mit
`STORAGE_MODE=datei`. Die Webseite hat keine eigene Datenbank und keinen Admin-Bereich. Anfragen aus
Angebots-, Kontakt- und Bewerbungsformular gehen per signiertem Webhook an den bestehenden n8n-Prozess, der sie
in die Unternehmensdatenbank schreibt, und per E-Mail an `LEAD_NOTIFY_TO`. Format und Prüfung in n8n:
`docs/n8n-webhook.md`. Der bisherige Datenbankmodus (`STORAGE_MODE=datenbank`, Migrationen und Admin über die
Einrichtungsseite) bleibt als Option erhalten; die dafür nötigen Schritte stehen in Abschnitt 12.

Die Menübezeichnungen im Hosting-Panel ändern sich gelegentlich. Die folgenden Schritte nennen
daher die Funktion, nicht einen festen Menüpfad. Wo ein Pfad genannt ist, ist er vor Ort zu prüfen.

## 1. Überblick

| Baustein | Lösung ohne Kommandozeile |
|---|---|
| Abhängigkeiten, Assets, OG-Bilder, Suchindex | im Release-Paket enthalten (`bin/build-release.sh`) |
| Konfiguration | `.env` im Paket, mit frischem `APP_KEY`, `SETUP_TOKEN` und `N8N_WEBHOOK_SECRET` |
| Speicherung der Anfragen | keine Datenbank: verschlüsselte Aufträge in `storage/outbox`, Webhook an n8n, Mail |
| Schöne URLs, Sperren, Cache-Header | `public/.htaccess` (Apache 2.4, alle Module mit `IfModule` abgesichert) |
| Document Root nicht änderbar | `.htaccess` im Paketordner leitet nach `public/` um und sperrt alle anderen Ordner |
| Diagnose, Staging-Schutz, Abschluss | Web-Einrichtung unter `/_einrichtung/` (Token aus `.env`) |
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
`storage`-Ordner (inklusive `storage/outbox` mit Rechten 700) mit `Require all denied` an, erzeugt die
`.env` (`APP_ENV=staging`, `APP_URL=https://neu.muellerhv.de`, `SHOW_DRAFTS=true`, `STORAGE_MODE=datei`,
`OUTBOX_MODE=inline`, frischer `APP_KEY`, `SETUP_TOKEN` und `N8N_WEBHOOK_SECRET`, Datenbankfelder
auskommentiert) und schreibt `LIESMICH-SFTP.txt`. Zum Schluss prüft es
das Paket (kein `.git`, keine PDF-Dateien, `vendor` und `public/assets/build` vorhanden) und
erstellt das ZIP.

Wichtig: Die `.env` im Paket enthält Schlüssel. Paket und ZIP nicht ins Repository legen
(`build/` steht in `.gitignore`), nicht per E-Mail versenden und nach dem Hochladen lokal löschen.
Jeder Lauf erzeugt neue Schlüssel. Bei einem Update einer bereits eingerichteten Umgebung die
`.env` des Servers behalten und nicht mit der neuen überschreiben, sonst werden wartende Outbox-Aufträge
unlesbar und das Webhook-Geheimnis passt nicht mehr zu n8n.

## 3. Hosting-Panel vorbereiten

1. **PHP-Version:** für die Subdomain `neu.muellerhv.de` PHP 8.3 oder neuer wählen. Benötigte
   Erweiterungen: mbstring, sodium, json, ctype, xmlwriter, fileinfo, openssl, empfohlen curl
   (n8n-Webhook, sonst Rückfall auf PHP-Streams); pdo_mysql nur bei `STORAGE_MODE=datenbank`. Die Diagnose
   zeigt, was fehlt.
2. **Document Root:** das Zielverzeichnis der Subdomain auf den Ordner `public` des hochgeladenen
   Pakets setzen, z. B. `/neu.muellerhv.de/public`. Alternative, falls das Panel das nicht zulässt:
   Zielverzeichnis auf den Paketordner selbst. Dann übernimmt die `.htaccess` im Paketordner, leitet
   intern nach `public/` um und sperrt `src`, `config`, `vendor`, `storage`, `templates`,
   `content`, `migrations`, `bin` sowie `.env` und alle versteckten Dateien (Antwort 403 bzw. 404).
3. **Keine Datenbank anlegen.** Sie wird im Standardbetrieb nicht benötigt.
4. **HTTPS:** Zertifikat für die Subdomain aktivieren (im Panel meist unter Domains bzw. SSL).

## 4. n8n vorbereiten und .env ausfüllen

In n8n (bestehender Prozess, der in die Unternehmensdatenbank schreibt):

1. Webhook-Knoten (POST, Option für den rohen Body aktiv) anlegen bzw. den vorhandenen verwenden und die
   Produktions-URL (https) notieren.
2. Den Wert `N8N_WEBHOOK_SECRET` aus der `.env` des Pakets in n8n als Geheimnis für die Signaturprüfung
   hinterlegen (Code-Knoten in `docs/n8n-webhook.md` Abschnitt 6).
3. Antwortmodus so einstellen, dass n8n erst nach dem Schreiben des Datensatzes antwortet und bei Fehlern einen
   Status ungleich 2xx liefert. Nur dann sendet die Webseite erneut. `uuid` als eindeutigen Schlüssel führen.

Die `.env` aus dem Paket lokal mit einem Texteditor (UTF-8) öffnen und ergänzen (Pflichtfelder):

- `N8N_WEBHOOK_URL`: Produktions-URL aus Schritt 1, mit `https://`.
- `N8N_WEBHOOK_SECRET`: nicht verändern (derselbe Wert steht in n8n).
- `MAIL_HOST`, `MAIL_PORT`, `MAIL_USER`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM`: SMTP-Zugang des
  Versandpostfachs.
- `LEAD_NOTIFY_TO`: Empfänger der Anfragen. Optional `BEWERBUNG_NOTIFY_TO` für Bewerbungen (mit PDF-Anhang),
  sonst gehen sie ebenfalls an `LEAD_NOTIFY_TO`.
- `APP_URL` prüfen (`https://neu.muellerhv.de`). `APP_KEY` und `SETUP_TOKEN` nicht verändern.

Fehlen Werte, nimmt die Webseite Anfragen trotzdem an; die Aufträge warten verschlüsselt in `storage/outbox` und
werden nach der Korrektur beim nächsten Formular bzw. nach spätestens fünf Minuten nachgeholt.

Die Datei darf auch später jederzeit ersetzt werden, die Anwendung liest sie bei jeder Anfrage.

## 5. Hochladen per SFTP

1. Im SFTP-Programm die Anzeige versteckter Dateien einschalten. `.env` und die
   `.htaccess`-Dateien beginnen mit einem Punkt und werden sonst weder angezeigt noch übertragen.
2. Den gesamten Inhalt des Paketordners in das Zielverzeichnis hochladen (ZIP vorher lokal
   entpacken, falls das Hosting kein Entpacken anbietet).
3. Schreibrechte: Ordner `storage` und alle Unterordner auf 755 setzen; kann der Webserver damit
   nicht schreiben (Diagnose meldet „storage/... beschreibbar“ als Fehler), 775 verwenden.
   `storage/outbox` möglichst auf 700, `.env` auf 640 bzw. 600 setzen, sofern das Hosting das erlaubt.

## 6. Einrichtung im Browser

1. `https://neu.muellerhv.de/_einrichtung/` aufrufen und den `SETUP_TOKEN` aus der `.env`
   eingeben. Nach fünf falschen Versuchen je IP-Adresse ist die Anmeldung 15 Minuten gesperrt.
2. **Diagnose** prüfen: Einträge mit „Fehler“ beheben (Lösungshinweis steht jeweils darunter),
   „Hinweis“ ist optional. Im Dateimodus wird statt der Datenbank geprüft: `storage/outbox` beschreibbar,
   `N8N_WEBHOOK_URL` gesetzt und mit https, `N8N_WEBHOOK_SECRET` gesetzt, SMTP konfiguriert,
   `LEAD_NOTIFY_TO` gesetzt. Es werden keine Geheimnisse angezeigt, nur ob Werte gesetzt und gültig sind.
3. Optional **Hash-Hilfe** für den Staging-Schutz (Abschnitt 7).
4. **Einrichtung abschließen:** bestätigen, dass die Diagnose geprüft und das Webhook-Geheimnis in n8n
   hinterlegt ist. Das schreibt `storage/setup.lock`, danach antwortet `/_einrichtung/` mit 404.
   Anschließend `SETUP_TOKEN` aus der `.env` entfernen und die Datei erneut hochladen. Für eine erneute
   Einrichtung `storage/setup.lock` per SFTP löschen und einen neuen Token (mindestens 32 zufällige Zeichen)
   setzen.

Migrationen und Admin-Anlage entfallen im Dateimodus; die Aktionen sind gesperrt, `/admin/` liefert 404.

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

`OUTBOX_MODE=inline` (im Paket gesetzt): Jede Anfrage erzeugt drei Aufträge in `storage/outbox` (Webhook an n8n,
interne Mail, Eingangsbestätigung), verschlüsselt mit libsodium (Schlüssel aus `APP_KEY`), Dateirechte 0600,
atomar geschrieben. Nach dem Senden der Antwort verarbeitet die Anwendung bis zu 15 fällige Aufträge. Unter
PHP-FPM wird die Verbindung zum Browser vorher geschlossen (`fastcgi_finish_request`), Besucher warten also nicht
auf n8n oder den Mailserver. Nach erfolgreichem Versand wird die Datei gelöscht, bei Bewerbungen nach dem
Mailversand auch die verschlüsselte PDF-Datei.

Bei Fehlern folgen Wiederholungen nach 1, 5, 15, 60 und 240 Minuten, bei Seitenaufrufen höchstens alle fünf
Minuten nachgeholt. Nach dem letzten Fehlversuch wird der Auftrag nach `storage/outbox/fehlgeschlagen/`
verschoben (Warnung im Log ohne personenbezogene Daten) und nach `LEAD_RETENTION_DAYS`, spätestens nach 30 Tagen
gelöscht (stündlich inline bzw. `php bin/retention.php`). Eine Dateisperre in `storage/cache` und eine Sperre je
Auftragsdatei (flock) verhindern Doppelversand. Fehler landen nur im Log (`storage/logs/app.log`), nie in der
Antwort. Fehlgeschlagene Aufträge können nur mit dem `APP_KEY` gelesen werden; zum erneuten Senden die Datei
zurück nach `storage/outbox/` verschieben.

Steht im Hosting ein Cronjob zur Verfügung, ist ein regelmäßiger Lauf zuverlässiger:
`php bin/worker.php --once` alle fünf Minuten (Pfad zum PHP-Binary und Arbeitsverzeichnis laut
Hosting-Dokumentation) und `OUTBOX_MODE=worker` setzen. Beide Varianten dürfen auch gleichzeitig
laufen.

## 9. Abnahme auf Staging

1. Startseite, einige Unterseiten, `/sitemap.xml` und `/health` aufrufen (`/health` meldet
   `{"status":"ok","datenbank":"nicht_verwendet"}`), `/admin/` liefert 404.
2. Angebotsformular unter `/angebot/` mit fiktiven Daten (z. B. Adresse unter example.org) absenden: Datensatz in
   n8n bzw. der Unternehmensdatenbank prüfen, Eingang der Benachrichtigung bei `LEAD_NOTIFY_TO` und der
   Eingangsbestätigung prüfen, Testdatensatz anschließend in der Unternehmensdatenbank löschen.
3. Per SFTP prüfen, dass `storage/outbox` danach keine `.job`-Dateien enthält.
4. Kontakt- und Bewerbungsformular ebenso (Bewerbung: PDF-Anhang in der internen Mail).
5. Stichprobe Sperren: `https://neu.muellerhv.de/.env`, `/storage/logs/app.log` und
   `/storage/outbox/` müssen 403 oder 404 liefern.

## 10. Fehlerbilder

| Bild | Ursache und Lösung |
|---|---|
| 503 „Die Seite ist vorübergehend nicht erreichbar“ | Startfehler der Anwendung: `.env` fehlt (versteckte Datei nicht hochgeladen), `APP_KEY` ungültig, `vendor` unvollständig. Details stehen im PHP-Fehlerlog (Meldung beginnt mit „HVM Startfehler“). |
| 500 direkt nach dem Hochladen, auch für statische Dateien | Meist eine vom Hosting nicht erlaubte Direktive in einer `.htaccess`. In `public/.htaccess` (bzw. in der Wurzel-`.htaccess`) die Zeile mit `Options` testweise mit `#` auskommentieren. |
| 500 nur für Seiten | Anwendungsfehler: `storage/logs/app.log` per SFTP herunterladen und die letzten Zeilen prüfen. Häufig fehlen Schreibrechte für `storage` oder eine PHP-Erweiterung (Diagnose). |
| 404 des Webservers für alle Seiten außer der Startseite | `.htaccess` nicht hochgeladen oder mod_rewrite nicht verfügbar. |
| Formular meldet Erfolg, aber keine Mail | `MAIL_*` bzw. `LEAD_NOTIFY_TO` fehlen oder sind falsch. Die Aufträge bleiben in `storage/outbox` und werden nach Korrektur beim nächsten Formular bzw. nach spätestens fünf Minuten nachgeholt. Hinweise im Log. |
| Formular meldet Erfolg, aber nichts in n8n | `N8N_WEBHOOK_URL` fehlt, ist kein https oder n8n antwortet mit einem Fehler (Log: „Webhook antwortet mit HTTP …“). Bei „Signatur ungültig“ in n8n stimmt `N8N_WEBHOOK_SECRET` nicht überein. Aufträge warten in `storage/outbox` bzw. liegen nach dem letzten Versuch in `storage/outbox/fehlgeschlagen/`. |
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
   neuen ersetzen, damit keine veralteten Dateien übrig bleiben). Neue Variablen aus der `.env` des Pakets
   bei Bedarf in die bestehende `.env` übernehmen, `APP_KEY` und `N8N_WEBHOOK_SECRET` des Servers behalten.
3. Den Twig-Cache `storage/cache/twig` leeren (Inhalt löschen), damit geänderte Templates sofort
   greifen.

## 12. Option: Betrieb mit Datenbank

Nur falls die Geschäftsführung den Datenbankmodus doch wünscht: im Hosting-Panel eine MySQL- bzw.
MariaDB-Datenbank anlegen, in der `.env` `STORAGE_MODE=datenbank` setzen und `DB_HOST`, `DB_PORT`, `DB_NAME`,
`DB_USER`, `DB_PASSWORD` einkommentieren und ausfüllen. Auf der Einrichtungsseite erscheinen dann zusätzlich
„Migrationen ausführen“ und „Erster Admin“ (TOTP-Geheimnis und Wiederherstellungscodes werden einmalig angezeigt).
Neue Migrationen bei Updates: `SETUP_TOKEN` setzen, `storage/setup.lock` löschen, unter `/_einrichtung/`
„Migrationen ausführen“ und wieder abschließen, vorher die Datenbank sichern (phpMyAdmin, Export). Der Webhook
sendet im Datenbankmodus nur Angebotsanfragen im älteren Format (`docs/n8n-webhook.md` Abschnitt 8).
