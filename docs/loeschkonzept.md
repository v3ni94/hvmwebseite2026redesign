# Löschkonzept

Stand: 23.09.2026. Status: Entwurf, Abstimmung mit dem Datenschutzbeauftragten und Freigabe durch die Geschäftsführung ausstehend.
Grundlage: MP Abschnitt 6.5 und 9, `docs/architektur.md` Abschnitt 4 und 10. Umsetzung: `bin/retention.php`, `Hvm\Service\Retention`.

Dieses Dokument beschreibt die technische Umsetzung. Es enthält keine rechtliche Bewertung. Welche Fristen gelten und ob einzelne Daten aus anderen Gründen länger aufzubewahren sind, legt die Geschäftsführung nach Abstimmung mit dem Datenschutzbeauftragten bzw. einem Rechtsanwalt fest.

## 1. Frist

| Einstellung | Wert |
|---|---|
| `LEAD_RETENTION_DAYS` | [Aufbewahrungsfrist festlegen] |

Solange die Variable leer ist, ist der Löschlauf deaktiviert. `bin/retention.php` gibt dann nur einen Hinweis aus und ändert nichts. Die Frist gilt einheitlich für alle unten genannten Datenarten. Sollen unterschiedliche Fristen gelten, ist eine Erweiterung nötig (je Datenart eine eigene Variable).

## 2. Was der Löschlauf tut

Stichtag ist jeweils der Zeitpunkt, an dem der Datensatz seinen abschließenden Status erhalten hat. Bei Leads ist das der letzte Statuswechsel im Verlauf (`lead_events`), ohne Verlauf die letzte Änderung (`updated_at`). Bei Kontaktanfragen und Bewerbungen ist es `updated_at`.

| Datenart | Bedingung | Maßnahme |
|---|---|---|
| Leads mit Status „spam“ | Stichtag älter als die Frist | Datensatz wird gelöscht, Verlauf und Warteschlangeneinträge (Outbox) werden über Fremdschlüssel mitgelöscht |
| Leads mit Status „verloren“ | Stichtag älter als die Frist | Anonymisierung (Abschnitt 3), Eintrag „Anonymisiert“ im Verlauf |
| Kontaktanfragen mit Status „erledigt“ oder „spam“ | `updated_at` älter als die Frist | Datensatz wird gelöscht |
| Bewerbungen mit Status „abgeschlossen“ oder „spam“ | `updated_at` älter als die Frist | verschlüsselte Datei in `storage/uploads` und Datensatz werden gelöscht |
| Outbox (Mail, Webhook) mit Status „sent“ oder „failed“ | Erstellung älter als die Frist | Nutzdaten (`payload`) werden geleert, der Eintrag bleibt als technischer Nachweis ohne Inhalt |

Leads mit den Status „neu“, „kontaktiert“, „angebot“ und „gewonnen“ werden nicht angefasst. Für gewonnene Leads gelten die Aufbewahrungsregeln der Vertragsverwaltung außerhalb der Webseite [Umgang mit gewonnenen Leads festlegen].

Liegt der Pfad einer Bewerbungsdatei außerhalb von `storage/uploads`, wird weder die Datei noch der Datensatz gelöscht. Der Lauf endet dann mit Exitcode 3 und zählt den Fall unter `dateien_fehler`. Fehlt die Datei bereits, wird der Datensatz gelöscht und der Fall unter `dateien_fehlend` gezählt.

## 3. Anonymisierung verlorener Leads

Geleert werden: Anrede, Vorname, Nachname, E-Mail, Telefon, Rolle, Kontaktanschrift, Objektstraße, Objektort, Nachricht, Notiz, Referrer sowie alle Notizen im Verlauf. Die Postleitzahl des Objekts wird auf die ersten zwei Ziffern gekürzt (PLZ-Bereich).

Erhalten bleiben für die Auswertung im Dashboard: Verwaltungsart, Anzahl der Einheiten, Baujahr, gewünschter Verwaltungsbeginn, aktueller Verwalter ja oder nein, PLZ-Bereich, Betreuungsgebiet, Kanalzuordnung (Quelle, utm-Parameter, Einstiegsseite), Preisstufen, Status, Zeitstempel und die Version des Datenschutzhinweises.

Ob diese Restdaten in ihrer Kombination noch einen Personenbezug erlauben (etwa bei kleinen Objekten in kleinen Orten), ist mit dem Datenschutzbeauftragten zu bewerten. Bei Bedarf werden weitere Felder in die Liste `Retention::ANONYMIZE_NULL` aufgenommen.

Ein anonymisierter Lead kann keine Notizen mehr erhalten. Ein erneuter Import des Altbestands befüllt anonymisierte Leads nicht wieder (`bin/import-legacy-leads.php` überspringt sie).

## 4. Betrieb

```
php bin/retention.php --dry-run   # nur zählen
php bin/retention.php             # ausführen
```

- Empfehlung: täglicher Lauf per Cron oder als Docker-Job, zuerst einige Tage nur mit `--dry-run` und Kontrolle der Zählwerte.
- Protokoll: `storage/logs/retention.log` mit Zeitpunkt, Frist und Zählwerten je Datenart. Keine Namen, E-Mail-Adressen, Anschriften oder Kennungen einzelner Datensätze.
- Exitcodes: 0 erfolgreich oder deaktiviert, 1 Abbruch (z. B. Datenbank nicht erreichbar), 3 Dateien konnten nicht gelöscht werden.
- Die Verarbeitung erfolgt in Blöcken von 500 Datensätzen je Transaktion.

## 5. Nicht abgedeckt

- Datenbank-Backups: gelöschte Daten bleiben in älteren Sicherungen enthalten, bis diese turnusgemäß überschrieben werden. Die Aufbewahrung der Backups ist in `docs/betrieb.md` zu regeln [Aufbewahrung Backups festlegen].
- Logdateien: `storage/logs` enthält keine personenbezogenen Daten (Filter in `Hvm\Support\Log`), eine Rotation ist trotzdem einzurichten [Log-Rotation festlegen].
- Anmeldeversuche im Admin-Bereich (`admin_login_attempts`, nur HMAC-Werte von E-Mail und IP): werden derzeit nicht bereinigt [Frist für Anmeldeprotokoll festlegen].
- CSV-Exporte aus dem Admin-Bereich: liegen nach dem Herunterladen außerhalb der Anwendung und unterliegen den internen Regeln der Hausverwaltung.
- Auskunfts- und Löschersuchen einzelner Personen: derzeit manuell über die Datenbank. Ein Werkzeug dafür ist nicht Teil dieses Stands [Prozess für Betroffenenanfragen festlegen].

## 6. Offene Punkte

- [Aufbewahrungsfrist festlegen] für verlorene Leads, Spam, erledigte Kontaktanfragen und abgeschlossene Bewerbungen, nach Abstimmung mit dem Datenschutzbeauftragten.
- Bewertung der verbleibenden Felder nach der Anonymisierung (Abschnitt 3).
- Die übrigen Platzhalter aus Abschnitt 2 und 5.
