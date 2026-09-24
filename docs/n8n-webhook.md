# Webhook an n8n (Dateimodus ohne Datenbank)

Stand: 24.09.2026. Grundlage: Entscheidung der Geschäftsführung vom 24.09.2026 (`docs/auftraggeber-angaben.md`):
Die Webseite erhält keine eigene Datenbank. Anfragen gehen direkt an den bestehenden n8n-Prozess, der sie in die
bestehende Unternehmensdatenbank schreibt. Einen Admin-Bereich auf der Webseite gibt es nicht.

Umsetzung: `STORAGE_MODE=datei` (Standard, `config/app.php`), Code in `src/Service/DateiAnfrageService.php`
(Aufbau der Nachrichten), `src/Service/FileOutbox.php` (Zwischenspeicher, Versand, Wiederholung) und
`src/Service/WebhookService.php` (Signatur). Der bisherige Datenbankmodus (`STORAGE_MODE=datenbank`) bleibt als
Option erhalten; er sendet nur für Angebotsanfragen einen Webhook im älteren Format `lead.created` (Abschnitt 8).

## 1. Ablauf

1. Formular absenden. Die Webseite prüft CSRF, Zeitfalle, Honeypot, Rate Limit (Dateien in `storage/ratelimit`)
   und die Eingaben. Bei Spamverdacht wird nichts gespeichert und nichts gesendet.
2. Je Anfrage entstehen drei Aufträge als Dateien in `storage/outbox/`: Webhook an n8n, interne Benachrichtigung
   an `LEAD_NOTIFY_TO` (Bewerbungen an `BEWERBUNG_NOTIFY_TO`, ersatzweise `LEAD_NOTIFY_TO`, mit PDF-Anhang) und
   Eingangsbestätigung an den Absender. Inhalt JSON, verschlüsselt mit libsodium secretbox (Schlüssel aus
   `APP_KEY`), Dateirechte 0600, atomar geschrieben.
3. Direkt nach der Antwort an den Browser (`OUTBOX_MODE=inline`) werden die Aufträge versendet und die Dateien
   gelöscht. Bei Fehlern folgen Wiederholungen nach 1, 5, 15, 60 und 240 Minuten. Nach dem letzten Fehlversuch
   wird der Auftrag nach `storage/outbox/fehlgeschlagen/` verschoben (Warnung im Log ohne personenbezogene Daten)
   und nach `LEAD_RETENTION_DAYS`, spätestens nach 30 Tagen gelöscht (`bin/retention.php` und stündlich inline).

**Wichtig:** Der Webhook ist die einzige dauerhafte Speicherung der Anfrage. n8n muss deshalb bei jedem Fehler
(Datenbank nicht erreichbar, Pflichtfeld fehlt, Signatur ungültig) mit einem HTTP-Status ungleich 2xx antworten,
damit die Webseite erneut sendet. Der Webhook-Knoten darf also nicht sofort mit 200 antworten, sondern erst,
wenn der Datensatz geschrieben ist (Antwortmodus „wenn der letzte Knoten fertig ist“ oder ein eigener Knoten
„Respond to Webhook“ am Ende; Bezeichnung je nach n8n-Version prüfen). Die Webseite wartet höchstens 5 Sekunden
(Verbindungsaufbau 3 Sekunden) auf die Antwort und folgt keinen Weiterleitungen.

## 2. HTTP-Anfrage

```
POST <N8N_WEBHOOK_URL>
Content-Type: application/json; charset=utf-8
User-Agent: hvm-website-webhook/1
X-HVM-Timestamp: 1790244000
X-HVM-Signature: <hex(HMAC-SHA256(N8N_WEBHOOK_SECRET, timestamp + "." + roher Body))>
X-HVM-Event: angebot.eingegangen | kontakt.eingegangen | bewerbung.eingegangen
X-HVM-Delivery: <Kennung des Auftrags, bei Wiederholungen gleich>
```

Der Body wird einmal beim Absenden erzeugt und bei Wiederholungen unverändert gesendet. Zeitstempel und Signatur
werden je Versuch neu berechnet.

## 3. Gemeinsame Felder

| Feld | Typ | Bedeutung |
|---|---|---|
| `schema` | Text | Formatversion, derzeit `hvm-website/anfrage-1`. Bei inkompatiblen Änderungen wird sie erhöht. |
| `event` | Text | `angebot.eingegangen`, `kontakt.eingegangen` oder `bewerbung.eingegangen` (wie `X-HVM-Event`) |
| `typ` | Text | `angebot`, `kontakt` oder `bewerbung` |
| `uuid` | Text | Kennung der Anfrage (UUID v4), Schlüssel für die Idempotenz (Abschnitt 6) |
| `created_at` | Text | Eingang in UTC, ISO 8601, z. B. `2026-09-24T10:00:00Z` |
| `consent_text_version` | Text | Version des bestätigten Datenschutz- bzw. Einwilligungshinweises |
| `consent_at` | Text | Zeitpunkt der Bestätigung in UTC (gleich `created_at`) |
| `data` | Objekt | Angaben je Typ (Abschnitt 4) |
| `attribution` | Objekt | nur `angebot` und `kontakt`: `source`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `landing_page`, `referrer` (nur Hostname), `region` (Betreuungsgebiet aus der Vorbelegung). Fehlende Werte sind `null`. |

`source` ist die abgeleitete Kanalzuordnung (`Hvm\Service\Attribution::deriveSource`): der Wert von
`utm_source`, sonst `google_ads`, `organisch`, `verweis` oder `direkt`.

## 4. Felder je Typ (`data`)

### angebot

| Feld | Typ | Bedeutung |
|---|---|---|
| `management_form` | Text | Verwaltungsart: `weg`, `miet` oder `se` |
| `management_form_label` | Text | Klartext, z. B. `WEG-Verwaltung` |
| `object.street`, `object.zip`, `object.city` | Text oder `null` | Objektanschrift |
| `object.year_of_construction` | Zahl oder `null` | Baujahr |
| `units.residential`, `units.commercial`, `units.parking` | Zahl | Wohn-, Gewerbeeinheiten, Stellplätze |
| `management_start` | Text oder `null` | gewünschter Beginn als `JJJJ-MM` |
| `has_current_manager` | `true`, `false` oder `null` | aktueller Verwalter vorhanden |
| `contact.salutation` | Text oder `null` | `frau`, `herr` oder `keine` (Werte aus `AngebotValidator::ANREDEN`) |
| `contact.first_name`, `contact.last_name` | Text | Name (Nachname Pflicht) |
| `contact.email` | Text | E-Mail-Adresse |
| `contact.phone` | Text oder `null` | Telefon |
| `role`, `role_label` | Text oder `null` | Rolle des Anfragenden (Werte aus `AngebotValidator::ROLLEN`) |
| `message` | Text oder `null` | Nachricht |

Eine Preisindikation gibt es im Dateimodus nicht (die Preisstaffeln lagen in der Datenbank der Webseite).

### kontakt

| Feld | Typ | Bedeutung |
|---|---|---|
| `subject`, `subject_label` | Text | Anliegen (Werte aus `KontaktValidator::ANLIEGEN`) und Klartext |
| `name` | Text | Name |
| `email` | Text | E-Mail-Adresse |
| `phone` | Text oder `null` | Telefon |
| `message` | Text | Nachricht |

### bewerbung

Nur Metadaten. Die PDF-Datei und das Anschreiben gehen ausschließlich per E-Mail an `BEWERBUNG_NOTIFY_TO`
(ersatzweise `LEAD_NOTIFY_TO`); die verschlüsselte Datei wird nach erfolgreichem Mailversand gelöscht.

| Feld | Typ | Bedeutung |
|---|---|---|
| `position` | Text oder `null` | gewünschte Stelle, `null` = Initiativbewerbung |
| `name`, `email` | Text | Name und E-Mail-Adresse |
| `phone` | Text oder `null` | Telefon |
| `has_message` | Wahrheitswert | Anschreiben vorhanden (Inhalt nur in der Mail) |
| `file.mime`, `file.size_bytes` | Text, Zahl | Typ (immer `application/pdf`) und Größe der Unterlagen |
| `file.delivery` | Text | `mail`: Unterlagen liegen der internen Mail bei |

`attribution` entfällt bei Bewerbungen.

## 5. Beispiel (fiktive Daten)

```json
{
  "schema": "hvm-website/anfrage-1",
  "event": "angebot.eingegangen",
  "typ": "angebot",
  "uuid": "3f2b8c1e-5d4a-4f6b-9c2d-1a2b3c4d5e6f",
  "created_at": "2026-09-24T10:00:00Z",
  "consent_text_version": "angebot-2026-09-entwurf-1",
  "consent_at": "2026-09-24T10:00:00Z",
  "data": {
    "management_form": "weg",
    "management_form_label": "WEG-Verwaltung",
    "object": { "street": "Musterweg 1", "zip": "50667", "city": "Musterstadt", "year_of_construction": 1995 },
    "units": { "residential": 12, "commercial": 1, "parking": 4 },
    "management_start": "2027-01",
    "has_current_manager": true,
    "contact": {
      "salutation": "frau",
      "first_name": "Erika",
      "last_name": "Beispiel",
      "email": "erika.beispiel@example.org",
      "phone": "0211 123456"
    },
    "role": "beirat",
    "role_label": "Beirat",
    "message": "Fiktive Testnachricht."
  },
  "attribution": {
    "source": "newsletter",
    "utm_source": "newsletter",
    "utm_medium": null,
    "utm_campaign": "herbst",
    "utm_term": null,
    "utm_content": null,
    "landing_page": "/weg-verwaltung/",
    "referrer": "www.example.org",
    "region": null
  }
}
```

Die Klartexte (`management_form_label`, `role_label`) stammen aus der Konfiguration der Webseite; maßgeblich für
die Weiterverarbeitung sind die Codes.

## 6. Prüfung in n8n

### Signatur und Zeitfenster

Voraussetzungen im Webhook-Knoten: Methode POST, Option für den rohen Body („Raw Body“) aktivieren, damit die
Signatur über exakt die empfangenen Bytes geprüft werden kann. Der Code-Knoten braucht das Node-Modul `crypto`
(bei selbst betriebenem n8n über die Umgebungsvariable `NODE_FUNCTION_ALLOW_BUILTIN=crypto` freigeben). Die
Bezeichnungen der Optionen und der Zugriff auf den rohen Body unterscheiden sich je nach n8n-Version und sind vor
Ort zu prüfen.

Code-Knoten (JavaScript, „Run Once for All Items“), direkt nach dem Webhook-Knoten. Das Geheimnis nicht in den
Code schreiben, sondern als Umgebungsvariable bzw. Credential hinterlegen (hier `HVM_WEBHOOK_SECRET`, derselbe Wert
wie `N8N_WEBHOOK_SECRET` der Webseite):

```js
const crypto = require('crypto');

const item = $input.first();
const headers = item.json.headers || {};
const timestamp = String(headers['x-hvm-timestamp'] || '');
const signature = String(headers['x-hvm-signature'] || '');
const secret = $env.HVM_WEBHOOK_SECRET;

// Roher Body als Text (Webhook-Knoten mit aktivierter Option "Raw Body")
const raw = (await this.helpers.getBinaryDataBuffer(0, 'data')).toString('utf8');

// Zeitfenster 5 Minuten gegen Wiedereinspielen
const now = Math.floor(Date.now() / 1000);
if (!/^\d+$/.test(timestamp) || Math.abs(now - Number(timestamp)) > 300) {
  throw new Error('Zeitstempel fehlt oder liegt außerhalb von 5 Minuten');
}

// HMAC-SHA256 über timestamp + "." + roher Body, Vergleich in konstanter Zeit
const expected = crypto.createHmac('sha256', secret).update(timestamp + '.' + raw).digest('hex');
if (!/^[a-f0-9]{64}$/.test(signature)
  || !crypto.timingSafeEqual(Buffer.from(expected, 'hex'), Buffer.from(signature, 'hex'))) {
  throw new Error('Signatur ungültig');
}

const body = JSON.parse(raw);
if (body.schema !== 'hvm-website/anfrage-1') {
  throw new Error('Unbekanntes Format: ' + body.schema);
}
return [{ json: body }];
```

Ein Fehler im Code-Knoten beendet den Lauf. Mit dem Antwortmodus aus Abschnitt 1 erhält die Webseite dann einen
Status ungleich 2xx und wiederholt den Versand. Bei ungültiger Signatur ist eine Wiederholung zwecklos; der
Auftrag landet nach dem letzten Versuch in `storage/outbox/fehlgeschlagen/`. Ursache ist dann fast immer ein
abweichendes Geheimnis.

### Idempotenz

Die Webseite sendet bei Zeitüberschreitung oder Fehlern erneut, auch wenn n8n den Datensatz schon geschrieben hat.
`uuid` ist daher als eindeutiger Schlüssel in der Unternehmensdatenbank zu führen: Einfügen nur, wenn die `uuid`
noch nicht existiert (bzw. „Upsert“ über `uuid`), und in diesem Fall ebenfalls mit 2xx antworten. `X-HVM-Delivery`
kennzeichnet den einzelnen Auftrag und eignet sich für Protokolle, nicht als fachlicher Schlüssel.

### Weiterverarbeitung

Nach der Prüfung per `typ` verzweigen (Switch-Knoten): `angebot` in die Tabelle der Verwaltungsanfragen, `kontakt`
und `bewerbung` nach Festlegung der Geschäftsführung. Der Datensatz enthält personenbezogene Daten: Ausführungsdaten
in n8n nur so lange aufbewahren wie nötig (Einstellung zur Speicherung erfolgreicher und fehlgeschlagener
Ausführungen) [Aufbewahrung der Ausführungsdaten in n8n festlegen].

## 7. Zuordnung zu den Feldern der Tabelle „Properties“ (Altseite)

Quelle: `docs/bestandsaufnahme-muellerhv-de.md` Abschnitt 4. Die Zieltabelle der Unternehmensdatenbank ist noch
nicht bestätigt [Zieltabelle und Feldnamen der Unternehmensdatenbank bestätigen]. Die folgende Zuordnung dient als
Ausgangspunkt:

| Feld „Properties“ | Feld im Webhook (`typ` = `angebot`) | Hinweis |
|---|---|---|
| ID | (vergibt die Unternehmensdatenbank) | zusätzlich `uuid` speichern (Idempotenz) |
| Contact Gender | `data.contact.salutation` | Werte `frau`, `herr`, `keine` ggf. umschlüsseln |
| Contact First Name / Last Name | `data.contact.first_name`, `data.contact.last_name` | |
| Contact Telephone / Email | `data.contact.phone`, `data.contact.email` | |
| Contact Street / Zip / City | (entfällt) | wird im neuen Formular nicht mehr abgefragt |
| management_start | `data.management_start` | Format `JJJJ-MM`, ggf. auf den Monatsersten ergänzen |
| Year of Construction | `data.object.year_of_construction` | |
| Street / Zip / City | `data.object.street`, `data.object.zip`, `data.object.city` | |
| Source | `attribution.source` | zusätzlich utm-Felder, `landing_page`, `referrer` speichern, wenn möglich |
| Created At | `created_at` | UTC |
| managementform_id | `data.management_form` | `weg`, `miet`, `se` auf die IDs der Verwaltungsformen abbilden |
| private_managementcost_id | (entfällt) | Preisstufen werden im Dateimodus nicht berechnet |
| commercial_managementcost_id | (entfällt) | wie oben |
| parking_managementcost_id | (entfällt) | wie oben |
| (neu) | `data.units.*`, `data.has_current_manager`, `data.role`, `data.message`, `consent_text_version`, `consent_at` | Einheitenzahlen, Verwalterwechsel, Rolle, Nachricht und Nachweis der Einwilligung ergänzen |

## 8. Datenbankmodus (Option)

Bei `STORAGE_MODE=datenbank` speichert die Webseite Anfragen in MariaDB und sendet nur für Angebotsanfragen einen
Webhook mit `X-HVM-Event: lead.created` und dem Body `{"event": "lead.created", "lead": {...}}` (Aufbau in
`Hvm\Service\LeadService::webhookBody`, inklusive Link in den Admin-Bereich). Signatur und Header sind identisch.
Für den beschlossenen Betrieb ohne Datenbank ist ausschließlich das Format aus den Abschnitten 3 bis 5 maßgeblich.
