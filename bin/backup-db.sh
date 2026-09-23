#!/usr/bin/env bash
#
# Datenbank-Backup mit mysqldump, Kompression und Verschlüsselung (MP Abschnitt 10 und 13).
# Verschlüsselung mit age (empfohlen, siehe Begründung unten), alternativ openssl AES-256
# ueber BACKUP_METHOD=openssl. Rotation ueber BACKUP_KEEP (Standard 14 Sicherungen).
#
# Voraussetzung: mariadb-dump (oder mysqldump), gzip, und je nach Methode age oder openssl.
# Schluessel liegen ausserhalb des Repositories, siehe .env bzw. Umgebungsvariablen unten.
#
# Warum age statt openssl als Standard: age verwendet ein einzelnes Schluesselpaar
# (X25519), keine Passwortverwaltung, kein Modus- oder KDF-Parameter, den man falsch waehlen
# kann, moderne authentifizierte Verschluesselung (ChaCha20-Poly1305), und die Sicherung kann
# mit dem oeffentlichen Schluessel verschluesselt werden, ohne dass das Backup-Skript selbst
# das Geheimnis kennen muss. openssl enc bleibt als Alternative, falls age auf dem Server
# nicht verfuegbar ist; dort ist eine Schluesseldatei (kein Passwort in der Kommandozeile) und
# AES-256-GCM oder AES-256-CBC mit Salt zu verwenden.
#
# Aufruf:
#   bin/backup-db.sh [--database=hvm_dev] [--out=/pfad/zu/backups]
#
# Umgebungsvariablen (aus .env oder Docker-Umgebung, nie im Repository):
#   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD
#   BACKUP_METHOD=age|openssl (Standard age)
#   BACKUP_AGE_RECIPIENT=age1...           (oeffentlicher Schluessel, Methode age)
#   BACKUP_OPENSSL_KEYFILE=/pfad/schluessel (Schluesseldatei ausserhalb des Repos, Methode openssl)
#   BACKUP_DIR=/pfad/zu/backups             (Standard: <Repo>/storage/backups, nicht im Repository)
#   BACKUP_KEEP=14                          (Anzahl aufzubewahrender Sicherungen je Datenbank)

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [ -f "${root_dir}/.env" ]; then
    set -a
    # shellcheck disable=SC1090
    source "${root_dir}/.env"
    set +a
fi

datenbank="${DB_NAME:-hvm_dev}"
ausgabe_verzeichnis="${BACKUP_DIR:-${root_dir}/storage/backups}"
methode="${BACKUP_METHOD:-age}"
aufbewahren="${BACKUP_KEEP:-14}"

for arg in "$@"; do
    case "$arg" in
        --database=*) datenbank="${arg#--database=}" ;;
        --out=*) ausgabe_verzeichnis="${arg#--out=}" ;;
        *)
            echo "Unbekannte Option: ${arg}" >&2
            exit 2
            ;;
    esac
done

: "${DB_HOST:?DB_HOST fehlt (siehe .env)}"
: "${DB_USER:?DB_USER fehlt (siehe .env)}"
: "${DB_PASSWORD:?DB_PASSWORD fehlt (siehe .env)}"
db_port="${DB_PORT:-3306}"

dump_werkzeug=""
for kandidat in mariadb-dump mysqldump; do
    if command -v "${kandidat}" >/dev/null 2>&1; then
        dump_werkzeug="${kandidat}"
        break
    fi
done
if [ -z "${dump_werkzeug}" ]; then
    echo "Weder mariadb-dump noch mysqldump gefunden." >&2
    exit 2
fi

mkdir -p "${ausgabe_verzeichnis}"
zeitstempel="$(date -u +%Y%m%dT%H%M%SZ)"
basisname="${datenbank}_${zeitstempel}"
dump_datei="${ausgabe_verzeichnis}/${basisname}.sql.gz"

echo "Sichere Datenbank '${datenbank}' von ${DB_HOST}:${db_port} nach ${dump_datei} ..."

MYSQL_PWD="${DB_PASSWORD}" "${dump_werkzeug}" \
    --host="${DB_HOST}" \
    --port="${db_port}" \
    --user="${DB_USER}" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --skip-add-locks \
    --default-character-set=utf8mb4 \
    "${datenbank}" \
    | gzip -9 > "${dump_datei}"

case "${methode}" in
    age)
        : "${BACKUP_AGE_RECIPIENT:?BACKUP_AGE_RECIPIENT fehlt (oeffentlicher age-Schluessel)}"
        if ! command -v age >/dev/null 2>&1; then
            echo "age nicht installiert, aber BACKUP_METHOD=age gesetzt." >&2
            exit 2
        fi
        age --encrypt --recipient "${BACKUP_AGE_RECIPIENT}" --output "${dump_datei}.age" "${dump_datei}"
        rm -f "${dump_datei}"
        dump_datei="${dump_datei}.age"
        ;;
    openssl)
        : "${BACKUP_OPENSSL_KEYFILE:?BACKUP_OPENSSL_KEYFILE fehlt (Pfad zur Schluesseldatei ausserhalb des Repos)}"
        if [ ! -f "${BACKUP_OPENSSL_KEYFILE}" ]; then
            echo "Schluesseldatei nicht gefunden: ${BACKUP_OPENSSL_KEYFILE}" >&2
            exit 2
        fi
        openssl enc -aes-256-cbc -pbkdf2 -iter 100000 -salt \
            -pass "file:${BACKUP_OPENSSL_KEYFILE}" \
            -in "${dump_datei}" -out "${dump_datei}.enc"
        rm -f "${dump_datei}"
        dump_datei="${dump_datei}.enc"
        ;;
    *)
        echo "Unbekannte BACKUP_METHOD: ${methode} (erlaubt: age, openssl)" >&2
        exit 2
        ;;
esac

echo "Sicherung verschluesselt: ${dump_datei}"

# Rotation: nur die juengsten $aufbewahren Sicherungen je Datenbank behalten.
muster="${ausgabe_verzeichnis}/${datenbank}_"*
anzahl=0
# shellcheck disable=SC2012
for datei in $(ls -1t ${muster} 2>/dev/null || true); do
    anzahl=$((anzahl + 1))
    if [ "${anzahl}" -gt "${aufbewahren}" ]; then
        echo "Entferne alte Sicherung: ${datei}"
        rm -f "${datei}"
    fi
done

echo "Fertig. ${anzahl} Sicherung(en) fuer '${datenbank}' vorhanden, davon maximal ${aufbewahren} aufbewahrt."
