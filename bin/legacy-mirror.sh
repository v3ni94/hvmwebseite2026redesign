#!/usr/bin/env bash
#
# Spiegelt die Altseite muellerhv.de nach /legacy (MP Phase 1, Abschnitt 13).
# Ruft danach automatisch bin/legacy-scrub.php auf, damit personenbezogene Daten
# entfernt werden, bevor irgendetwas weiterverarbeitet oder committet wird.
#
# Hinweis: muellerhv.de ist aus dieser Entwicklungsumgebung gesperrt (Domainbeschränkung
# des Agentenproxys). Dieses Skript auf einem Rechner ohne diese Beschränkung ausfuehren,
# zum Beispiel lokal oder auf einem separaten Arbeitsplatz. /legacy steht in .gitignore
# und wird nicht committet.
#
# Aufruf: bin/legacy-mirror.sh [Domain]
# Standard-Domain: muellerhv.de

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
domain="${1:-muellerhv.de}"
ziel="${root_dir}/legacy"

if ! command -v wget >/dev/null 2>&1; then
    echo "wget nicht gefunden. Bitte installieren und erneut ausfuehren." >&2
    exit 2
fi

echo "Spiegele https://${domain} nach ${ziel} ..."
mkdir -p "${ziel}"

wget \
    --mirror \
    --page-requisites \
    --adjust-extension \
    --convert-links \
    --no-parent \
    --wait=2 \
    --random-wait \
    --tries=3 \
    --directory-prefix="${ziel}" \
    --no-host-directories \
    --user-agent="hvm-legacy-mirror/1.0 (Relaunch-Inventur)" \
    "https://${domain}/" || {
        status=$?
        echo "wget beendete sich mit Status ${status}. Bei Serverfehlern (HTTP 500, Timeouts)" >&2
        echo "das Skript spaeter erneut ausfuehren, wget setzt einen bereits vorhandenen Mirror fort." >&2
    }

echo "Mirror abgeschlossen (oder mit Warnungen). Starte Bereinigung personenbezogener Daten ..."
php "${root_dir}/bin/legacy-scrub.php" --path="${ziel}"

echo "Fertig. Ergebnis liegt in ${ziel}, bereinigt laut Protokoll oben."
echo "Naechster Schritt: php bin/legacy-inventory.php --path=${ziel}"
