#!/bin/sh
#
# Schritte nach dem Einspielen eines neuen Stands (docs/betrieb.md Abschnitt 1.1).
#
#   1. Datenbankmigrationen (bin/migrate.php, parallele Läufe sind per Datenbanksperre serialisiert)
#   2. Twig-Cache leeren (storage/cache/twig), damit geänderte Templates sofort gelten
#   3. Build: Assets mit .gz-Varianten, Open-Graph-Bilder, Suchindex (wie "composer build")
#   4. Rauchtest gegen /health, sofern --url angegeben ist
#
# Aufruf:
#   bin/deploy-post.sh [--ohne-build] [--url=https://www.muellerhv.de]
#
# Docker: Der Build ist Teil des Images, und der Nginx-Container hat eine eigene Kopie von public/.
# Deshalb im laufenden PHP-Container mit --ohne-build ausführen (nicht per "run --rm", sonst trifft das
# Leeren des Caches einen Wegwerf-Container):
#   docker compose exec php sh bin/deploy-post.sh --ohne-build --url=http://nginx
#
# Enthält keine Zugangsdaten. Die Datenbankverbindung kommt aus .env bzw. der Umgebung (DB_*).

set -eu

cd "$(dirname "$0")/.."

BUILD=1
URL=""
for arg in "$@"; do
    case "$arg" in
        --ohne-build) BUILD=0 ;;
        --url=*) URL="${arg#--url=}" ;;
        *) echo "Unbekannte Option: $arg" >&2; exit 2 ;;
    esac
done

echo "1/4 Migrationen"
php bin/migrate.php

echo "2/4 Twig-Cache leeren"
if [ -d storage/cache/twig ]; then
    find storage/cache/twig -mindepth 1 -delete
fi

if [ "$BUILD" -eq 1 ]; then
    echo "3/4 Build"
    php bin/build-assets.php --quiet
    php bin/build-og-images.php --quiet
    php bin/build-search-index.php
else
    echo "3/4 Build übersprungen (--ohne-build)"
fi

if [ -n "$URL" ]; then
    echo "4/4 Rauchtest ${URL%/}/health"
    if command -v curl >/dev/null 2>&1; then
        ANTWORT=$(curl -fsS --max-time 10 "${URL%/}/health")
    else
        ANTWORT=$(wget -q -T 10 -O - "${URL%/}/health")
    fi
    echo "$ANTWORT"
    case "$ANTWORT" in
        *'"datenbank":"ja"'*) ;;
        *) echo "Warnung: Datenbank laut /health nicht erreichbar." >&2; exit 1 ;;
    esac
else
    echo "4/4 Rauchtest übersprungen (ohne --url)"
fi

echo "Fertig."
