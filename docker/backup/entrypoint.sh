#!/usr/bin/env bash
# Startet den Cron-Daemon im Vordergrund, damit der Container laeuft. bin/backup-db.sh liest
# seine Konfiguration aus Umgebungsvariablen (DB_*, BACKUP_*), die docker-compose.yml aus der
# .env in diesen Dienst durchreicht.
set -euo pipefail
echo "Backup-Dienst gestartet, Zeitplan siehe /etc/crontabs/root."
exec crond -f -l 2
