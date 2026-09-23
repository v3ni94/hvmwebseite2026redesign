# Backup-Dienst: fuehrt bin/backup-db.sh periodisch aus (Docker-Variante zu einem Cronjob
# auf dem Host, siehe docs/betrieb.md Abschnitt Backup). Enthaelt mariadb-client (mariadb-dump),
# age fuer die Verschluesselung und dcron fuer die Zeitsteuerung im Container.
FROM alpine:3.20

RUN apk add --no-cache mariadb-client bash age dcron tzdata

WORKDIR /app
COPY bin/backup-db.sh /app/bin/backup-db.sh
RUN chmod +x /app/bin/backup-db.sh

COPY docker/backup/crontab /etc/crontabs/root
COPY docker/backup/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
