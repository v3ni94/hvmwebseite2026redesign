#!/usr/bin/env bash
#
# Stellt eine mit bin/backup-db.sh erzeugte Sicherung wieder her (MP Abschnitt 10 und 13).
# Entschluesselt (age oder openssl, wie bei der Sicherung), entpackt und spielt die Sicherung
# per mariadb/mysql in eine Zieldatenbank ein. Sicherheitsabfrage vor jedem Einspielen,
# da eine bestehende Zieldatenbank ueberschrieben wird.
#
# Aufruf:
#   bin/restore-db.sh --file=/pfad/zur/sicherung.sql.gz.age --database=hvm_test [--yes]
#
# Umgebungsvariablen (aus .env oder Docker-Umgebung, nie im Repository):
#   DB_HOST, DB_PORT, DB_USER, DB_PASSWORD
#   BACKUP_METHOD=age|openssl (muss zur Sicherung passen, Standard age)
#   BACKUP_AGE_IDENTITY=/pfad/zum/privaten/age-schluessel   (Methode age)
#   BACKUP_OPENSSL_KEYFILE=/pfad/schluessel                  (Methode openssl)

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [ -f "${root_dir}/.env" ]; then
    set -a
    # shellcheck disable=SC1090
    source "${root_dir}/.env"
    set +a
fi

sicherungsdatei=""
zieldatenbank=""
ohne_abfrage=0
methode="${BACKUP_METHOD:-age}"

for arg in "$@"; do
    case "$arg" in
        --file=*) sicherungsdatei="${arg#--file=}" ;;
        --database=*) zieldatenbank="${arg#--database=}" ;;
        --yes) ohne_abfrage=1 ;;
        *)
            echo "Unbekannte Option: ${arg}" >&2
            exit 2
            ;;
    esac
done

if [ -z "${sicherungsdatei}" ] || [ -z "${zieldatenbank}" ]; then
    echo "Aufruf: bin/restore-db.sh --file=/pfad/zur/sicherung.sql.gz.age --database=hvm_test [--yes]" >&2
    exit 2
fi
if [ ! -f "${sicherungsdatei}" ]; then
    echo "Sicherungsdatei nicht gefunden: ${sicherungsdatei}" >&2
    exit 2
fi

: "${DB_HOST:?DB_HOST fehlt (siehe .env)}"
: "${DB_USER:?DB_USER fehlt (siehe .env)}"
: "${DB_PASSWORD:?DB_PASSWORD fehlt (siehe .env)}"
db_port="${DB_PORT:-3306}"

if [ "${ohne_abfrage}" -ne 1 ]; then
    echo "Achtung: Datenbank '${zieldatenbank}' auf ${DB_HOST}:${db_port} wird ueberschrieben."
    read -r -p "Fortfahren? Zieldatenbank exakt eintippen zum Bestaetigen: " bestaetigung
    if [ "${bestaetigung}" != "${zieldatenbank}" ]; then
        echo "Abgebrochen: Eingabe stimmt nicht mit der Zieldatenbank ueberein." >&2
        exit 1
    fi
fi

arbeitsverzeichnis="$(mktemp -d)"
cleanup() {
    rm -rf "${arbeitsverzeichnis}"
}
trap cleanup EXIT

entpackte_datei="${arbeitsverzeichnis}/sicherung.sql.gz"

case "${methode}" in
    age)
        : "${BACKUP_AGE_IDENTITY:?BACKUP_AGE_IDENTITY fehlt (Pfad zum privaten age-Schluessel)}"
        if [ ! -f "${BACKUP_AGE_IDENTITY}" ]; then
            echo "age-Schluesseldatei nicht gefunden: ${BACKUP_AGE_IDENTITY}" >&2
            exit 2
        fi
        age --decrypt --identity "${BACKUP_AGE_IDENTITY}" --output "${entpackte_datei}" "${sicherungsdatei}"
        ;;
    openssl)
        : "${BACKUP_OPENSSL_KEYFILE:?BACKUP_OPENSSL_KEYFILE fehlt (Pfad zur Schluesseldatei ausserhalb des Repos)}"
        if [ ! -f "${BACKUP_OPENSSL_KEYFILE}" ]; then
            echo "Schluesseldatei nicht gefunden: ${BACKUP_OPENSSL_KEYFILE}" >&2
            exit 2
        fi
        openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 \
            -pass "file:${BACKUP_OPENSSL_KEYFILE}" \
            -in "${sicherungsdatei}" -out "${entpackte_datei}"
        ;;
    *)
        echo "Unbekannte BACKUP_METHOD: ${methode} (erlaubt: age, openssl)" >&2
        exit 2
        ;;
esac

echo "Entpacke und spiele Sicherung nach '${zieldatenbank}' ein ..."
mysql_client=""
for kandidat in mariadb mysql; do
    if command -v "${kandidat}" >/dev/null 2>&1; then
        mysql_client="${kandidat}"
        break
    fi
done
if [ -z "${mysql_client}" ]; then
    echo "Weder mariadb noch mysql gefunden." >&2
    exit 2
fi

MYSQL_PWD="${DB_PASSWORD}" "${mysql_client}" \
    --host="${DB_HOST}" --port="${db_port}" --user="${DB_USER}" \
    -e "CREATE DATABASE IF NOT EXISTS \`${zieldatenbank}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

gunzip -c "${entpackte_datei}" | MYSQL_PWD="${DB_PASSWORD}" "${mysql_client}" \
    --host="${DB_HOST}" --port="${db_port}" --user="${DB_USER}" \
    "${zieldatenbank}"

anzahl_tabellen="$(MYSQL_PWD="${DB_PASSWORD}" "${mysql_client}" \
    --host="${DB_HOST}" --port="${db_port}" --user="${DB_USER}" \
    -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${zieldatenbank}';")"

echo "Wiederherstellung abgeschlossen: '${zieldatenbank}' enthaelt ${anzahl_tabellen} Tabelle(n)."
