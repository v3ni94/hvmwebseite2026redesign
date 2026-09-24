#!/usr/bin/env bash
#
# Erzeugt ein Release-Paket für Webhosting ohne Kommandozeile (SFTP, z. B. Apache mit PHP-FPM).
# Anleitung für den Betrieb: docs/deploy-sftp.md, im Paket LIESMICH-SFTP.txt.
#
# Aufruf:
#   bin/build-release.sh [ausgabeordner] [zip-datei]
#     ausgabeordner  Standard build/release (steht in .gitignore)
#     zip-datei      Standard <ausgabeordner>.zip, also daneben
#
# Umgebungsvariablen (optional):
#   RELEASE_APP_ENV   Standard staging
#   RELEASE_APP_URL   Standard https://neu.muellerhv.de
#
# Standard des Pakets: STORAGE_MODE=datei (keine Datenbank, Anfragen per Webhook an n8n und per Mail) und
# OUTBOX_MODE=inline. Das Paket enthält eine .env mit frisch erzeugtem APP_KEY, SETUP_TOKEN und
# N8N_WEBHOOK_SECRET (denselben Wert in n8n hinterlegen). Paket und ZIP daher
# nie ins Repository legen und nur über sichere Wege weitergeben.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${1:-$ROOT/build/release}"
case "$OUT" in /*) ;; *) OUT="$PWD/$OUT" ;; esac
OUT="${OUT%/}"
ZIP="${2:-$OUT.zip}"
case "$ZIP" in /*) ;; *) ZIP="$PWD/$ZIP" ;; esac
APP_ENV_WERT="${RELEASE_APP_ENV:-staging}"
APP_URL_WERT="${RELEASE_APP_URL:-https://neu.muellerhv.de}"
MARKER=".hvm-release"

fehler() { echo "Fehler: $*" >&2; exit 1; }
schritt() { echo "==> $*"; }

command -v php >/dev/null || fehler "php fehlt"
command -v composer >/dev/null || fehler "composer fehlt"
command -v rsync >/dev/null || fehler "rsync fehlt"
command -v zip >/dev/null || fehler "zip fehlt"

# Sicherheitsnetz: nie ins Projekt selbst (ausser build/) oder in einen fremden, nicht leeren Ordner schreiben
case "$OUT/" in
    "$ROOT/") fehler "Ausgabeordner darf nicht das Projekt sein" ;;
    "$ROOT/build/"*) ;;
    "$ROOT/"*) fehler "Ausgabeordner innerhalb des Projekts nur unter build/ erlaubt" ;;
esac
if [ -d "$OUT" ] && [ -n "$(ls -A "$OUT")" ] && [ ! -f "$OUT/$MARKER" ]; then
    fehler "$OUT ist nicht leer und kein früheres Release-Paket, Abbruch"
fi

schritt "Ausgabeordner $OUT vorbereiten"
rm -rf "$OUT"
mkdir -p "$OUT"
touch "$OUT/$MARKER"

schritt "Quellcode kopieren (ohne Tests, Doku, Node, Legacy, Geheimnisse)"
rsync -a \
    --exclude='/.git/' \
    --exclude='/.github/' \
    --exclude='/tests/' \
    --exclude='/docs/' \
    --exclude='/node_modules/' \
    --exclude='/legacy/' \
    --exclude='/build/' \
    --exclude='/vendor/' \
    --exclude='/docker/' \
    --exclude='/docker-compose.yml' \
    --exclude='/.dockerignore' \
    --exclude='/playwright.config.js' \
    --exclude='/playwright-report/' \
    --exclude='/test-results/' \
    --exclude='/package.json' \
    --exclude='/package-lock.json' \
    --exclude='/phpunit.xml.dist' \
    --exclude='/.phpunit.cache/' \
    --exclude='/.phpunit.result.cache' \
    --exclude='/README.md' \
    --exclude='/.env' \
    --exclude='/.gitignore' \
    --exclude='/.env.*' \
    --include='/.env.example' \
    --exclude='/storage/' \
    --exclude='/public/assets/build/' \
    --exclude='/public/og/' \
    --exclude='/public/assets/search-index.json*' \
    --exclude='/.claude/' \
    --exclude='/bin/legacy-*' \
    --exclude='/bin/import-legacy-leads.php' \
    --exclude='/bin/build-release.sh' \
    --exclude='*.pdf' \
    --exclude='*.log' \
    --exclude='.DS_Store' \
    --exclude='*.gz' \
    --exclude='*.br' \
    "$ROOT/" "$OUT/"
cp "$ROOT/.env.example" "$OUT/.env.example"

schritt "Abhängigkeiten ohne Entwicklungspakete installieren"
(
    cd "$OUT"
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --optimize-autoloader \
        --classmap-authoritative --no-interaction --no-progress --no-scripts
)
# Aus Quellen installierte Pakete bringen Versionsverwaltung mit: entfernen
find "$OUT/vendor" -type d \( -name '.git' -o -name '.github' \) -prune -exec rm -rf {} +

# .env vor dem Build: der Suchindex nimmt bei SHOW_DRAFTS=true (Staging) auch Entwürfe auf
schritt ".env erzeugen (APP_ENV=$APP_ENV_WERT, STORAGE_MODE=datei, frischer APP_KEY, SETUP_TOKEN und N8N_WEBHOOK_SECRET)"
APP_KEY_NEU="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
SETUP_TOKEN_NEU="$(php -r 'echo bin2hex(random_bytes(24));')"
N8N_SECRET_NEU="$(php -r 'echo bin2hex(random_bytes(32));')"
php -- "$OUT/.env" "$APP_ENV_WERT" "$APP_URL_WERT" "$APP_KEY_NEU" "$SETUP_TOKEN_NEU" "$N8N_SECRET_NEU" <<'PHP'
<?php
[$_, $ziel, $env, $url, $key, $token, $n8nSecret] = $argv;
$drafts = $env === 'production' ? 'false' : 'true';
$stand = gmdate('d.m.Y H:i');
$inhalt = <<<ENV
# .env für Webhosting per SFTP, erzeugt von bin/build-release.sh am {$stand} UTC.
# Enthält Schlüssel: vertraulich behandeln, nicht per E-Mail weitergeben, nicht ins Repository.
# Vollständige Liste aller Variablen mit Erläuterung: .env.example

# Anwendung
APP_ENV={$env}
APP_URL={$url}
# Schlüssel für Verschlüsselung und Signaturen (frisch erzeugt). Nach dem Start nicht mehr ändern,
# sonst werden wartende Outbox-Aufträge und Bewerbungsdateien unlesbar.
APP_KEY={$key}
SHOW_DRAFTS={$drafts}
PRICE_INDICATION_ENABLED=false
AI_CRAWLERS=true

# Einrichtung ohne Kommandozeile unter {$url}/_einrichtung/ (nur solange storage/setup.lock fehlt).
# Nach Abschluss der Einrichtung entfernen.
SETUP_TOKEN={$token}

# Speicherung: datei = keine Datenbank der Webseite (Entscheidung der Geschäftsführung vom 24.09.2026).
# Anfragen gehen verschlüsselt über storage/outbox per signiertem Webhook an n8n (einzige dauerhafte
# Speicherung, n8n schreibt in die Unternehmensdatenbank) und per Mail an LEAD_NOTIFY_TO.
STORAGE_MODE=datei

# n8n-Webhook (PFLICHT): Produktions-URL des Webhook-Knotens mit https eintragen.
N8N_WEBHOOK_URL=
# PFLICHT, frisch erzeugt: denselben Wert in n8n für die Signaturprüfung hinterlegen (docs/n8n-webhook.md).
N8N_WEBHOOK_SECRET={$n8nSecret}
N8N_WEBHOOK_ALLOW_HTTP_INTERNAL=false

# Mail (SMTP mit TLS, PFLICHT): Zugang des Postfachs, das Benachrichtigungen und Eingangsbestätigungen versendet
MAIL_HOST=
MAIL_PORT=587
MAIL_USER=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM=
MAIL_FROM_NAME="Hausverwaltung Müller GmbH"
# PFLICHT: Empfänger der Anfragen [Empfängeradresse Leads festlegen]
LEAD_NOTIFY_TO=
# optional: Empfänger der Bewerbungen (mit PDF-Anhang), leer = LEAD_NOTIFY_TO
BEWERBUNG_NOTIFY_TO=

# inline: Mail und Webhook werden direkt nach der Antwort verarbeitet (kein Cronjob nötig).
# worker: nur wenn ein Cronjob alle 5 Minuten php bin/worker.php --once ausführt.
OUTBOX_MODE=inline

# Datenbank: nur bei STORAGE_MODE=datenbank (bisheriger Betrieb mit Admin-Bereich). Im Standard nicht anlegen.
# DB_HOST=
# DB_PORT=3306
# DB_NAME=
# DB_USER=
# DB_PASSWORD=
# DB_SOCKET=

# Staging-Schutz (nur APP_ENV=staging): Zeile aus der Hash-Hilfe unter /_einrichtung/ einsetzen,
# Format 'benutzer:hash' in einfachen Anführungszeichen. Leer = kein Passwortschutz.
STAGING_BASIC_AUTH=

# Sicherheit
# Webhosting ohne vorgeschalteten Proxy: leer lassen
TRUSTED_PROXIES=
SESSION_IDLE_TIMEOUT=1800
# [Aufbewahrungsfrist festlegen]. Im Dateimodus werden fehlgeschlagene Outbox-Aufträge nach dieser Frist,
# spätestens nach 30 Tagen gelöscht.
LEAD_RETENTION_DAYS=

ENV;
file_put_contents($ziel, $inhalt);
chmod($ziel, 0640);
PHP

schritt "Build: Assets, Open-Graph-Bilder, Suchindex"
(
    cd "$OUT"
    COMPOSER_ALLOW_SUPERUSER=1 composer build --no-interaction
)
# Quellen der Assets werden zur Laufzeit nicht gebraucht
rm -rf "$OUT/resources"

schritt "storage anlegen (leer, per .htaccess gesperrt)"
for d in storage storage/logs storage/cache storage/cache/twig storage/uploads storage/ratelimit storage/outbox storage/outbox/fehlgeschlagen; do
    mkdir -p "$OUT/$d"
done
# Twig-Cache aus dem Build verwerfen (enthält absolute Pfade der Build-Umgebung)
rm -rf "$OUT/storage/cache/twig/"*
printf 'Require all denied\n' > "$OUT/storage/.htaccess"
chmod 700 "$OUT/storage/outbox" "$OUT/storage/outbox/fehlgeschlagen"
for d in logs cache cache/twig uploads ratelimit outbox outbox/fehlgeschlagen; do
    printf 'Require all denied\n' > "$OUT/storage/$d/.htaccess"
done

# Zusätzliche Sperre für den Fall, dass die Document Root auf dem Projektordner liegt (Wurzel-.htaccess)
for d in src config vendor templates content migrations bin; do
    [ -d "$OUT/$d" ] && printf 'Require all denied\n' > "$OUT/$d/.htaccess"
done

schritt "LIESMICH-SFTP.txt schreiben"
cat > "$OUT/LIESMICH-SFTP.txt" <<'TXT'
Hausverwaltung Müller GmbH, Webseite: Inbetriebnahme per SFTP (ohne Kommandozeile)
==================================================================================

Ausführliche Fassung: docs/deploy-sftp.md im Repository, Webhook-Format: docs/n8n-webhook.md.

Wichtig vorab
- Die Webseite hat KEINE eigene Datenbank (STORAGE_MODE=datei). Anfragen aus Angebots-,
  Kontakt- und Bewerbungsformular gehen per signiertem Webhook an den bestehenden n8n-Prozess,
  der sie in die Unternehmensdatenbank schreibt, und per E-Mail an LEAD_NOTIFY_TO. Bis zum
  Versand liegen sie wenige Sekunden verschlüsselt in storage/outbox, danach werden sie gelöscht.
  Es gibt keinen Admin-Bereich.
- Dieses Paket enthält die Datei .env mit frisch erzeugtem APP_KEY, SETUP_TOKEN und
  N8N_WEBHOOK_SECRET. Paket vertraulich behandeln, nicht per E-Mail weitergeben, nicht in ein
  Repository legen.
- .env und .htaccess sind versteckte Dateien. Im SFTP-Programm die Anzeige versteckter
  Dateien einschalten, sonst werden sie nicht hochgeladen.

1. Hosting-Panel
   a) PHP-Version 8.3 oder neuer für die Domain bzw. Subdomain wählen.
   b) Document Root (Zielverzeichnis) der Subdomain auf den Ordner public dieses Pakets setzen,
      z. B. /neu.muellerhv.de/public. Geht das nicht, auf den Paketordner zeigen lassen:
      Die .htaccess im Paketordner leitet dann nach public um und sperrt alle anderen Ordner.
   c) Keine Datenbank anlegen, sie wird nicht benötigt.

2. n8n vorbereiten
   - Im bestehenden n8n-Prozess einen Webhook-Knoten (POST) anlegen bzw. den vorhandenen
     verwenden und dessen Produktions-URL (https) notieren.
   - Den Wert N8N_WEBHOOK_SECRET aus der .env dieses Pakets in n8n für die Signaturprüfung
     hinterlegen (Code-Beispiel in docs/n8n-webhook.md). Bei Fehlern muss n8n mit einem
     HTTP-Status ungleich 2xx antworten, dann sendet die Webseite erneut.

3. .env anpassen (lokal mit einem Texteditor, UTF-8)
   - N8N_WEBHOOK_URL: Produktions-URL aus Schritt 2 (Pflicht).
   - N8N_WEBHOOK_SECRET: nicht verändern, derselbe Wert steht in n8n (Pflicht).
   - SMTP-Zugang (MAIL_HOST, MAIL_PORT, MAIL_USER, MAIL_PASSWORD, MAIL_FROM) und
     LEAD_NOTIFY_TO (Empfänger der Anfragen) eintragen (Pflicht). Optional BEWERBUNG_NOTIFY_TO
     für Bewerbungen (mit PDF-Anhang).
   - APP_URL prüfen (Standard https://neu.muellerhv.de).

4. Hochladen per SFTP
   - Den gesamten Inhalt dieses Ordners in das Zielverzeichnis hochladen (inklusive .env und
     aller .htaccess-Dateien).
   - Ordner storage und alle Unterordner beschreibbar machen: Rechte 755, falls der Webserver
     nicht schreiben kann 775. storage/outbox auf 700, falls das Hosting das erlaubt.
     .env auf 640 bzw. 600 setzen, falls das Hosting das erlaubt.

5. Einrichtung im Browser
   - https://neu.muellerhv.de/_einrichtung/ aufrufen und den SETUP_TOKEN aus der .env eingeben.
   - Diagnose prüfen: alle Einträge sollten OK sein (Hinweise sind optional). Geprüft werden
     u. a. storage/outbox beschreibbar, N8N_WEBHOOK_URL mit https, Webhook-Geheimnis, SMTP und
     LEAD_NOTIFY_TO.
   - Optional: Hash-Hilfe für den Staging-Schutz nutzen und die erzeugte Zeile
     STAGING_BASIC_AUTH='benutzer:hash' in die .env übernehmen, Datei erneut hochladen.
   - "Einrichtung abschließen" wählen. Danach ist /_einrichtung/ nicht mehr erreichbar (404).
     SETUP_TOKEN anschließend aus der .env entfernen.

6. Prüfen
   - Startseite, einige Unterseiten und https://neu.muellerhv.de/health aufrufen
     (Antwort {"status":"ok","datenbank":"nicht_verwendet"}).
   - Angebotsformular unter /angebot/ mit fiktiven Daten absenden und prüfen, ob der Datensatz
     in n8n bzw. der Unternehmensdatenbank ankommt und die Mails bei LEAD_NOTIFY_TO und beim
     Absender eintreffen. storage/outbox muss danach leer sein.

Mail und Webhook ohne Cronjob
- OUTBOX_MODE=inline ist gesetzt: Webhook und Mails werden direkt nach dem Absenden eines
  Formulars verschickt. Bei Fehlern (n8n oder SMTP nicht erreichbar) wird nach 1, 5, 15, 60 und
  240 Minuten erneut gesendet, jeweils beim nächsten Formular bzw. Seitenaufruf nach frühestens
  5 Minuten. Nach dem letzten Fehlversuch landet der Auftrag verschlüsselt in
  storage/outbox/fehlgeschlagen und wird nach LEAD_RETENTION_DAYS, spätestens nach 30 Tagen
  gelöscht. Hinweise stehen in storage/logs/app.log (ohne personenbezogene Daten).
- Steht im Hosting ein Cronjob zur Verfügung, kann alternativ alle 5 Minuten
  "php bin/worker.php --once" laufen (dann OUTBOX_MODE=worker).

Fehlerbilder
- HTTP 503 "vorübergehend nicht erreichbar": Startfehler, meist fehlende oder fehlerhafte .env
  bzw. fehlender Ordner vendor. .env und vendor prüfen, Details im PHP-Fehlerlog des Hostings.
- HTTP 500 direkt nach dem Hochladen: häufig eine nicht erlaubte Direktive in einer .htaccess.
  In public/.htaccess die Zeile "Options -Indexes -MultiViews" testweise mit # auskommentieren.
  Außerdem storage auf Schreibrechte prüfen. Details in storage/logs/app.log und im PHP-Fehlerlog,
  das je nach Hosting im Panel (Bereich Logs bzw. PHP-Einstellungen) oder als Datei im
  Webspace liegt.
- Seiten außer der Startseite liefern 404 vom Webserver: mod_rewrite fehlt oder .htaccess wurde
  nicht hochgeladen.
- Formular meldet Erfolg, aber nichts kommt in n8n an: N8N_WEBHOOK_URL bzw. Geheimnis prüfen,
  Aufträge warten in storage/outbox und werden nach der Korrektur erneut gesendet.
TXT

schritt "Kontrolle"
[ -f "$OUT/vendor/autoload.php" ] || fehler "vendor/autoload.php fehlt"
[ -f "$OUT/public/assets/build/manifest.json" ] || fehler "Asset-Build fehlt"
[ -f "$OUT/public/.htaccess" ] || fehler "public/.htaccess fehlt"
[ -f "$OUT/.htaccess" ] || fehler "Wurzel-.htaccess fehlt"
if find "$OUT" -name '.git' -o -name '*.pdf' | grep -q .; then
    fehler "Paket enthält .git-Ordner oder PDF-Dateien"
fi
for verboten in tests docs node_modules legacy .github; do
    [ ! -e "$OUT/$verboten" ] || fehler "$verboten ist im Paket"
done
rm -f "$OUT/$MARKER"

schritt "ZIP erstellen: $ZIP"
mkdir -p "$(dirname "$ZIP")"
rm -f "$ZIP"
(
    cd "$OUT"
    zip -r -q -X "$ZIP" .
)
touch "$OUT/$MARKER"

GROESSE="$(du -h "$ZIP" | cut -f1)"
echo "Fertig: $OUT"
echo "ZIP:    $ZIP ($GROESSE)"
echo "Hinweis: Die .env im Paket enthält APP_KEY, SETUP_TOKEN und N8N_WEBHOOK_SECRET. Vertraulich behandeln."
echo "Hinweis: Denselben N8N_WEBHOOK_SECRET in n8n für die Signaturprüfung hinterlegen (docs/n8n-webhook.md)."
