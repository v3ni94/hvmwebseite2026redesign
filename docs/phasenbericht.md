# Phasenbericht

Stand: 23.09.2026. Grundlage: Masterprompt Abschnitt 13 (Phasen 0 bis 7), Commit-Historie, `docs/architektur.md`, `docs/recht-offene-fragen.md`, `docs/redirects.md`, `docs/logo/nachzeichnung-pruefung.md`, `docs/phase0.md`, `docs/platzhalter-report.md` und die Berichte der laufenden Prüfungen. Details und Fundstellen zu jedem einzelnen Punkt in `docs/offene-punkte.md`.

## Phase 0: Voraussetzung außerhalb des Neubaus

**Status: teilweise**

Fertig: Der Neubau arbeitet nicht auf der Altseite, der Zugriff aus der Entwicklungsumgebung ist gesperrt, dies ist dokumentiert.

Offen: Die Abstellung der öffentlichen Ausgabe von Leaddaten auf der Altseite und die Leerung des Caches sind laut Geschäftsführung noch in Arbeit. Die Beantragung der Entfernung betroffener URLs in der Google Search Console steht noch aus. Ebenso die Prüfung, ob eine Meldung nach Art. 33 DSGVO und eine Benachrichtigung nach Art. 34 DSGVO erforderlich sind.

Freigaben der Geschäftsführung nötig: Bestätigung, dass Datenausgabe und Cache erledigt sind; Beauftragung der Search-Console-Bereinigung; Veranlassung der DSGVO-Prüfung durch Datenschutzbeauftragten oder Rechtsanwalt.

## Phase 1: Inventar

**Status: teilweise**

Fertig: Bestandsaufnahme der Altseite liegt vor (`docs/bestandsaufnahme-muellerhv-de.md`), Bildinventar dokumentiert (`docs/bildinventar.md`).

Offen: Ein vollständiger Mirror der Altseite konnte aus der Entwicklungsumgebung nicht erstellt werden (Egress-Sperre). Die vollständige URL-Liste der Altseite fehlt daher, mehrere Redirect-Einträge in `docs/redirects.md` sind nur „vermutet“ statt „belegt“.

Freigaben der Geschäftsführung nötig: keine direkte Freigabe, aber Bereitstellung eines Zugriffswegs auf die Altseite (z. B. externer Mirror) oder Verzicht darauf mit Risikoakzeptanz für unvollständige Redirects.

## Phase 2: Designsystem

**Status: fertig**

Fertig: Design-Tokens, Typografie, Kennlinie, Farbwelt und Komponenten sind in `docs/designsystem.md` und im CSS umgesetzt. Die Logo-Nachzeichnung liegt vor, ist aber gesondert zu behandeln (siehe Phase 3/7).

Offen: keine über Phase 3/7 hinausgehenden Punkte.

Freigaben der Geschäftsführung nötig: Freigabe des Styleguide bislang nicht dokumentiert bestätigt.

## Phase 3: Seitentemplates

**Status: fertig**

Fertig: Seiten für Start, Leistungen, Verwalterwechsel, Wissen, Kontakt und weitere sind als Templates umgesetzt (`templates/pages/*`).

Offen: Verbleibende allgemeine Platzhalter „[bestätigen]“ auf Start- und Wertgutachten-Seite (siehe `docs/offene-punkte.md` A11).

Freigaben der Geschäftsführung nötig: Freigabe der Prototypen ist laut Ablaufregel gesammelt nach Staging-Fähigkeit vorgesehen, bislang nicht dokumentiert erteilt.

## Phase 4: Leadstrecke und Admin

**Status: teilweise**

Fertig: Datenbank (Migrationen 0001 bis 0010), Angebotsformular, Verarbeitung inklusive Missbrauchsschutz (Honeypot, Zeitprüfung, Rate Limiting), Admin-Bereich mit TOTP, Grundgerüst für Import der Altbestandsdaten, Test-Suiten (Unit, Integration, teilweise e2e).

Offen: Kontaktformular ist laut Planung Teil der noch nicht abgeschlossenen Welle 2a. Keine admin-seitige Download-Route für Bewerbungsunterlagen. `composer test` ist aktuell nicht durchgehend stabil grün, unter anderem wegen paralleler Änderungen anderer Agenten an Sicherheits- und HTTP-Code und gemeinsam genutzter Testdatenbank. Playwright-Test für die Angebotsformularstrecke ohne JavaScript fehlt. Mapping der Altdatenbank für den Leadimport unvollständig (`config/legacy-mapping.php`).

Freigaben der Geschäftsführung nötig: Empfängeradresse für Lead-Benachrichtigungen, Aufbewahrungsfrist für Anfragen (`LEAD_RETENTION_DAYS`), n8n-Adresse und Beschränkung des Webhooks auf https, Vorgehen bei der APP_KEY-Umstellung (Mindestlänge 32 Byte) in bestehenden Umgebungen.

## Phase 5: Inhalte

**Status: teilweise**

Fertig: Wissensartikel und Seitentexte liegen größtenteils als Entwurf vor, Freigabestatus wird über `config/freigaben.php` geführt, Inhaltsplan für Wissen und FAQ liegt vor (`docs/wissen-inhaltsplan.md`).

Offen: 42 Platzhalter laut `docs/platzhalter-report.md`, davon unter anderem Notfallnummer, Portal-URL, Sprechzeiten, Antwortzeiten der Eingangsbestätigungen, Vertragslaufzeiten, Zertifizierungsangaben, Zugang für Mieter, Ablauf der Zugangsvergabe zum Portal. Vollständige Liste in `docs/offene-punkte.md`, Gruppe A und D.

Freigaben der Geschäftsführung nötig: sämtliche unter Gruppe A in `docs/offene-punkte.md` genannten inhaltlichen Bestätigungen, insbesondere Notfallnummer, Portal-URL, Sprechzeiten, Antwortzeiten, Vertragslaufzeiten und die Regionen-/Betreuungsform-Angaben.

## Phase 6: Recht, SEO, Performance, Sicherheit

**Status: teilweise**

Fertig: Pflichtseiten (Impressum, Datenschutz, Barrierefreiheit) als Gerüst umgesetzt, strukturierte Daten und Redirect-Logik implementiert, Lighthouse- und axe-Prüfungen liegen als lokale Testläufe vor (`docs/pruefbericht.md`), PII-Prüfwerkzeug (`bin/check-pii.php`) und Security-Header sind eingerichtet.

Offen: Sämtliche unter `docs/recht-offene-fragen.md` gelisteten Fragen zu Impressum, Datenschutzerklärung und Barrierefreiheitserklärung sind unverändert offen, keine ist final freigegeben. Prüfergebnisse aus `docs/pruefbericht.md` stammen aus lokalen `php -S`-Testläufen vom 23.09.2026 und sind vor Livegang gegen die echte Staging-/Produktionsumgebung zu wiederholen. Aus früheren Prüfungen bekannte offene Sicherheitspunkte (IP-Sperre über mehrere Konten hinweg, Timing bei unbekannten Admin-Konten, Härtung von `TRUSTED_PROXIES`, doppelt escapte Wissen-Links) sind nicht erneut bearbeitet. Redirects: mehrere Einträge nur „vermutet“, ein Eintrag (Immobilienangebote) bewusst als 302 bis zur Klärung der Datenquelle.

Freigaben der Geschäftsführung nötig: Freigabe von Impressum und Datenschutzerklärung (jeweils nach Prüfung durch Anwalt beziehungsweise Datenschutzbeauftragten), Freigabe der Logo-Nachzeichnung beziehungsweise Beschaffung der Originalvektordatei, Entscheidung zur Datenquelle Immobilienangebote.

## Phase 7: Livegang

**Status: offen**

Fertig: Checkliste in Ansätzen vorbereitet (Redirects, Monitoring-Absicht dokumentiert).

Offen: DNS-Umstellung darf laut Vorgabe erst nach Freigabe erfolgen, diese liegt nicht vor. Sicherung der alten Seite als statisches Archiv noch nicht durchgeführt. 404-Monitoring über vier Wochen noch nicht begonnen, da noch kein Livegang. Voraussetzung ist zudem der Abschluss von Phase 0 (Datenleck) und Phase 6 (Recht).

Freigaben der Geschäftsführung nötig: ausdrückliche Freigabe der DNS-Umstellung, Bestätigung, dass alle blockierenden Punkte aus Phase 0, 4, 5 und 6 (siehe `docs/offene-punkte.md`, Spalte „Blockierend“) geklärt sind, sowie der allgemeine Freigabe- und Commit-Vorbehalt aus den laufenden Prüfungen.
