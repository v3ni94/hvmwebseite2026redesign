# Offene Punkte vor Livegang

Stand: 24.09.2026. Vollständige, deduplizierte Liste aller Platzhalter und offenen Entscheidungen aus `php bin/check-placeholders.php --report` (`docs/platzhalter-report.md`), `docs/recht-offene-fragen.md`, `docs/redirects.md`, `docs/logo/nachzeichnung-pruefung.md`, MP Abschnitt 15, `docs/phase0.md` sowie den Prüfberichten dieser und vorangegangener Sitzungen. Gruppiert nach Zuständigkeit. Mehrfach an unterschiedlichen Stellen auftretende Punkte sind zu einer Zeile zusammengefasst, alle Fundstellen sind genannt.

Spalte „Blockierend“: **ja** = ohne Klärung darf die betroffene Seite oder Funktion nicht live gehen. **nein** = kann nachgezogen werden, ohne den Livegang zu verhindern.

## A. Geschäftsführung

| Nr. | Punkt | Fundstelle | Blockierend |
|---|---|---|---|
| A1 | erledigt (24.09.2026): Notfallnummer 02431 9550300 (zentrale Nummer, außerhalb der Bürozeiten in der Leitung bleiben) in `config/unternehmen.php` (`notfall_telefon`, `notfall_hinweis`), Notfall- und Serviceseite, Leistungsseiten, Artikel `notfall-was-tun`, `erreichbarkeit`, `eigentuemerportal`, FAQ Mieter | `docs/auftraggeber-angaben.md` | nein |
| A2 | erledigt (24.09.2026): Portal-URL https://portal.muellerhv.de in `config/unternehmen.php` (`portal_url`), Serviceseite, Artikel `eigentuemerportal`, `erreichbarkeit` | `docs/auftraggeber-angaben.md` | nein |
| A3 | USt-IdNr. ergänzen, falls vorhanden | `config/unternehmen.php:30`, `docs/recht-offene-fragen.md` I4 | nein |
| A4 | Aufbewahrungsfrist für Anfragen ohne Vertragsschluss festlegen (`LEAD_RETENTION_DAYS`), Löschlauf `bin/retention.php` laut Architektur geplant, noch nicht vorhanden | `config/app.php:57`, `docs/architektur.md` Abschnitt 5, `docs/recht-offene-fragen.md` D13 | ja |
| A5 | Empfängeradresse für Lead-Benachrichtigungen (`LEAD_NOTIFY_TO`) festlegen | `config/app.php:48`, `docs/architektur.md` Abschnitt 5 | ja |
| A6 | Teilweise erledigt (24.09.2026): Betreuungsgebiete bundesweit nach 42 PLZ-Bereichen bestätigt, umgesetzt in `config/staedte.php` und den Stadtseiten `/hausverwaltung-<stadt>/` (ersetzt `config/standorte.php`). Offen: ob die Absenderadressen der Verwaltungssoftware eigene Büros, Partnerbüros oder Postanschriften sind; bis zur Klärung werden keine Anschriften je Stadt und kein LocalBusiness je Stadt veröffentlicht. Stadttexte (`content/staedte/*.md`) einzeln freigeben (`freigabe: ja`) | `config/staedte.php`, `content/staedte/`, `docs/auftraggeber-angaben.md` | ja (je Stadtseite, Seite ohne Freigabe liefert in Produktion 404) |
| A7 | Stellenangebote für die Karriereseite ergänzen | `config/stellen.php:7` | nein |
| A8 | Intervall der Objektbegehungen und feste Ansprechperson je Objekt festlegen | Bericht „content“ dieser Sitzung, MP Abschnitt 6/7 | nein |
| A9 | Klären, ob Objekte außerhalb des Bestands vermittelt/verkauft werden | Bericht „content“ dieser Sitzung | nein |
| A10 | Antwortzeit für automatische Eingangsbestätigungen festlegen (Bewerbung, Kontakt, Angebotsanfrage) | `templates/emails/bewerbung-bestaetigung.html.twig:10`, `.txt.twig:6`, `templates/emails/kontakt-bestaetigung.html.twig:10`, `.txt.twig:6`, `templates/emails/lead-bestaetigung.html.twig:30`, `.txt.twig:16` | ja |
| A11 | erledigt (23.09.2026): Startseite und Wertgutachten-Seite, verbleibender allgemeiner Platzhalter „[bestätigen]“ in den Kommentaren aufgelöst | `templates/pages/start.html.twig:3`, `templates/pages/wertgutachten.html.twig:5` | ja |
| A12 | Mapping der Altdatenbank für den Leadimport ergänzen (drei Stellen) und Status für Altbestand sowie Zeitzone der Altdatenbank festlegen | `config/legacy-mapping.php:14,19,28,36,42` | nein (Import vor Livegang nicht zwingend) |
| A13 | Ablauf der Zugangsvergabe zum Eigentümerportal bestätigen | `content/wissen/eigentuemerportal.md:15,55` | nein |
| A14 | Funktionsumfang des Eigentümerportals bestätigen | `content/wissen/eigentuemerportal.md:35` | nein |
| A15 | Statusanzeige für Nutzer im Eigentümerportal bestätigen | `content/wissen/eigentuemerportal.md:43` | nein |
| A16 | Datenschutzhinweise zum Eigentümerportal ergänzen | `content/wissen/eigentuemerportal.md:66` | ja (hängt mit Datenschutzerklärung zusammen, siehe B-Liste) |
| A17 | Zugang für Mieter zum Portal/Funktionen bestätigen | `content/faq/mieter.yaml:37`, `content/wissen/eigentuemerportal.md:49`, `content/wissen/erreichbarkeit.md:38`, `content/wissen/schadensmeldung.md:57` | nein |
| A18 | erledigt (24.09.2026): Bürozeiten Montag bis Freitag, 8 bis 16 Uhr (`firma.buerozeiten`, `firma.oeffnungszeiten`) auf Kontakt, Service, Stadtseiten, Artikel `erreichbarkeit`, FAQ Mieter, dazu openingHoursSpecification im Organization-Schema | `config/unternehmen.php`, `templates/pages/kontakt.html.twig`, `templates/pages/service.html.twig`, `templates/pages/stadt.html.twig`, `content/faq/mieter.yaml`, `content/wissen/erreichbarkeit.md` | nein |
| A19 | erledigt (24.09.2026): Bürobesuche nur nach vorheriger Terminabstimmung (bestätigt), Artikel `erreichbarkeit` angepasst | `content/wissen/erreichbarkeit.md` | nein |
| A20 | Leistungsumfang der Betriebskostenabrechnung bestätigen | `content/wissen/betriebskostenabrechnung.md:82` | nein |
| A21 | Vertragslaufzeiten und Kündigungsfristen der HVM bestätigen | `content/wissen/vertragslaufzeit-verwaltervertrag.md:77` | ja |
| A22 | Aktuelle Mitgliedschaften (VZIV, IVD) bestätigen | `content/wissen/zertifizierter-verwalter.md:74`, `docs/recht-offene-fragen.md` I9 | nein |
| A23 | Angabe zur Zertifizierung nach § 26a WEG der betreuenden Mitarbeiter bestätigen | `content/wissen/zertifizierter-verwalter.md:74` | nein |
| A24 | erledigt (24.09.2026): Logo-Nachzeichnung durch die Geschäftsführung freigegeben. Empfehlung bleibt, die Originalvektordatei beim früheren Dienstleister anzufordern (nicht blockierend) | `docs/logo/nachzeichnung-pruefung.md` Titelzeile, Abschnitt 5 | nein |
| A25 | Fachlich klären, ob die gemessene, CI-fremde Fläche `#B0B1B3` in der Logo-Nachzeichnung bewusste Gestaltung oder Kompressionsartefakt ist | `docs/logo/nachzeichnung-pruefung.md` Abschnitt 2, 5 | nein |
| A26 | Datenquelle für Immobilienangebote (`/ff/immobilien/`) klären, danach Redirect von 302 auf 301 umstellen | `docs/redirects.md` | nein |
| A27 | Vollständige URL-Liste der Altseite besorgen (Mirror aus Entwicklungsumgebung nicht möglich), vermutete Redirect-Einträge bestätigen oder entfernen | `docs/redirects.md`, `docs/phase0.md` | nein |
| A28 | Meldung der betroffenen URLs des Datenlecks in der Google Search Console beantragen (Phase 0) | `docs/phase0.md` | ja |
| A29 | Bestätigen, dass Ausgabe der Leaddaten auf der Altseite abgestellt und Cache geleert ist (Stand: in Arbeit) | `docs/phase0.md` | ja |
| A30 | Freigabe der in vorangegangenen Prüfungen genannten Sicherheits- und Inhaltsänderungen sowie eines etwaigen Commits | Bericht „sec“ dieser Sitzung | ja |
| A31 | Stand-Datum der Datenschutzerklärung bei Freigabe aktualisieren | `docs/recht-offene-fragen.md` D18 | nein |
| A32 | Freigabe des Impressums insgesamt | `docs/recht-offene-fragen.md` I13 | ja |
| A33 | Freigabe der Datenschutzerklärung insgesamt (nach DSB) | `docs/recht-offene-fragen.md` D19 | ja |
| A34 | Technischer Teil erledigt (23.09.2026): `WebhookService` lässt in Produktion nur https zu, http nur für interne Hosts (Dienstname ohne Punkt, private IP) mit `N8N_WEBHOOK_ALLOW_HTTP_INTERNAL=true`, sonst wartet der Outbox-Eintrag mit Fehlermeldung ohne URL (`tests/Unit/Service/WebhookServiceTest.php`, `tests/Integration/OutboxWorkerTest.php`). Offen: n8n-Adresse bestätigen und `N8N_WEBHOOK_ALLOW_HTTP_INTERNAL` passend setzen | Bericht „sec“ dieser Sitzung, `docs/betrieb.md` Abschnitt 1.4 | ja |
| A35 | Vorgehen bei APP_KEY-Umstellung (jetzt mindestens 32 Byte, base64:...) mit bestehenden Umgebungen abstimmen; kürzere oder nicht dekodierbare Schlüssel gelten als fehlend, Produktion startet dann nicht, ein bestehender kürzerer Schlüssel macht damit verschlüsselte TOTP-Geheimnisse und Uploads unlesbar. Lokale `.env` ist gültig | Bericht „sec“ dieser Sitzung, `docs/architektur.md` Abschnitt 5 | ja |
| A36 | erledigt (24.09.2026): Formulierungen Verwalterwechsel (HVM übernimmt nach Beschluss die Abstimmung zur Übergabe mit dem bisherigen Verwalter, fehlende Unterlagen werden festgehalten und nachgefordert) und Termin (Bürobesuche nur nach vorheriger Terminabstimmung) bestätigt | `templates/pages/verwalterwechsel.html.twig`, `templates/pages/start.html.twig`, `templates/pages/kontakt.html.twig`, `docs/auftraggeber-angaben.md` | nein |
| A37 | erledigt (24.09.2026): Wertgutachten erstellen eigens ausgebildete Mitarbeiter, teilweise externe Sachverständige (ersetzt „[Sachverständige benennen]“), keine Zertifizierung behauptet | `templates/pages/wertgutachten.html.twig`, `content/wissen/wertermittlung-verfahren.md` | nein |
| A38 | erledigt (24.09.2026): Staging-Subdomain `neu.muellerhv.de` | `docs/betrieb.md` Abschnitt 1.2, `.env.example` | nein |

## B. Anwalt / Datenschutzbeauftragter

| Nr. | Punkt | Fundstelle | Blockierend |
|---|---|---|---|
| B1 | Rechtsgrundlage der Anbieterkennzeichnung (§ 5 TMG vs. § 5 DDG) vor Livegang verifizieren | `docs/recht-offene-fragen.md` I1 | ja |
| B2 | Gewerberechtliche Erlaubnis nach § 34c GewO, zuständige Behörde und Impressumsangaben klären | `docs/recht-offene-fragen.md` I2 | ja |
| B3 | Angaben zur Berufshaftpflichtversicherung prüfen | `docs/recht-offene-fragen.md` I3 | ja |
| B4 | Aussage zur Teilnahme an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle vor Livegang prüfen | `docs/recht-offene-fragen.md` I5 | ja |
| B5 | Hinweis zur EU-Plattform zur Online-Streitbeilegung (eingestellt 2025) verifizieren, auch in AGB/Verträgen | `docs/recht-offene-fragen.md` I6 | nein |
| B6 | Angabe für redaktionelle Inhalte nach Medienstaatsvertrag (§ 18 Abs. 2 MStV) prüfen, ggf. Name und Anschrift ergänzen | `docs/recht-offene-fragen.md` I7 | ja |
| B7 | Anschrift ohne „c/o“-Zusatz als ladungsfähig bestätigen | `docs/recht-offene-fragen.md` I8 | ja |
| B8 | Datenschutzbeauftragten benennen (falls benennungspflichtig) mit Kontaktdaten | `docs/recht-offene-fragen.md` D1 | ja |
| B9 | Auftragsverarbeitungsvertrag Hosting bestätigen, Serverstandort und Vertragspartner/Rechtsform verifizieren (IONOS Dedicated Server) | `docs/recht-offene-fragen.md` D2, `docs/betrieb.md` | ja |
| B10 | Umfang und Speicherdauer der Server-Logfiles (Traefik, Nginx) festlegen und mit der Konfiguration abgleichen | `docs/recht-offene-fragen.md` D3 | ja |
| B11 | TDDDG-Ausnahme für unbedingt erforderliche Speicherung (Sitzungscookie `hvm_sid`) verifizieren, Leerlaufzeit bestätigen | `docs/recht-offene-fragen.md` D4 | ja |
| B12 | Einsatz von Google Ads und Weitergabe der Klickkennung (`gclid`) klären | `docs/recht-offene-fragen.md` D5 | nein |
| B13 | Löschfrist für Zählwerte des Missbrauchsschutzes (`rate_limits`, `admin_login_attempts`) festlegen | `docs/recht-offene-fragen.md` D6 | nein |
| B14 | Rechtsgrundlage des Angebotsformulars prüfen, Datenschutztext im Formular freigeben | `docs/recht-offene-fragen.md` D7 | ja |
| B15 | Kontaktformular (Welle 2a): Felder mit Datenschutzerklärung abgleichen, Übermittlung an n8n klären | `docs/recht-offene-fragen.md` D8 | ja |
| B16 | Rechtsgrundlage für Bewerberdaten prüfen, Aufbewahrungsfrist nach Absage festlegen, Zugriffskreis festlegen | `docs/recht-offene-fragen.md` D9 | ja |
| B17 | SMTP-Dienstleister benennen, Auftragsverarbeitungsvertrag und Serverstandort bestätigen | `docs/recht-offene-fragen.md` D10 | ja |
| B18 | Betrieb und Standort von n8n klären, Auftragsverarbeiter benennen, Zulässigkeit von http:// für den Webhook klären (siehe A34) | `docs/recht-offene-fragen.md` D11, Bericht „sec“ dieser Sitzung | ja |
| B19 | Drittlandübermittlung für alle Dienstleister prüfen | `docs/recht-offene-fragen.md` D12 | ja |
| B20 | Aufbewahrungsfristen (Anfragen, handels-/steuerrechtlich, Datensicherungen) durch Steuerberater bestätigen, Aufbewahrungsdauer der Sicherungen festlegen | `docs/recht-offene-fragen.md` D13 | ja |
| B21 | Bezeichnung der Aufsichtsbehörde (Landesbeauftragte für Datenschutz NRW) verifizieren | `docs/recht-offene-fragen.md` D14 | nein |
| B22 | Analysewerkzeug (Reichweitenmessung) festlegen, falls gewünscht | `docs/recht-offene-fragen.md` D15 | nein |
| B23 | Klären, ob Informations- oder Meldepflichten aus Altdatenübernahme und Datenleck der Altseite bestehen | `docs/recht-offene-fragen.md` D16, `docs/phase0.md` | ja |
| B24 | Verzeichnis von Verarbeitungstätigkeiten und Auftragsverarbeitungsverträge aktualisieren | `docs/recht-offene-fragen.md` D17 | nein |
| B25 | Prüfen, ob eine Pflicht nach BFSG oder anderen Vorschriften zur Barrierefreiheit besteht, ggf. vorgeschriebene Inhalte und zuständige Behörde ergänzen | `docs/recht-offene-fragen.md` B1 | nein |
| B26 | Werbliche Aussagen (Rahmenverträge, 24/7-Notdienst, Eigentümerportal) wettbewerbsrechtlich vor Livegang durchsehen | `docs/recht-offene-fragen.md` Abschnitt 4 | nein |
| B27 | Prüfung Meldung Art. 33 DSGVO und Benachrichtigung Art. 34 DSGVO zum Datenleck der Altseite veranlassen, Frist von 72 Stunden verifizieren | `docs/phase0.md` | ja |

## C. Technik / Hosting

| Nr. | Punkt | Fundstelle | Blockierend |
|---|---|---|---|
| C1 | Stand der Vereinbarkeit und Prüfmethode der Barrierefreiheitserklärung nach durchgeführter Prüfung eintragen (axe-core, manuelle Prüfung) | `docs/recht-offene-fragen.md` B2 | ja |
| C2 | Bekannte Einschränkungen der Barrierefreiheit nach Prüfung ergänzen (insbesondere PDF) | `docs/recht-offene-fragen.md` B3 | nein |
| C3 | Datum der letzten Überprüfung der Barrierefreiheit nach Prüfung eintragen, jährliche Wiederholung einplanen | `docs/recht-offene-fragen.md` B5 | nein |
| C4 | Nebenbefund: doppeltes Escaping in `templates/pages/wissen.html.twig` Zeile 36 korrigieren (`&amp;q=` statt `&q=`), Suchbegriff geht im Zielgruppenfilter verloren | Bericht „sec“ dieser Sitzung | nein |
| C5 | Keine admin-seitige Download-Route für Bewerbungsunterlagen vorhanden; bei Ergänzung Content-Disposition attachment, nosniff und Anmeldeprüfung vor `decryptToStream()` vorsehen | Bericht „sec“ dieser Sitzung | nein |
| C6 | erledigt (23.09.2026): `tests/e2e/angebot.spec.js` prüft die Angebotsstrecke mit JavaScript (Schritte, Fortschritt, Prüfung, Absenden, Danke-Seite) und ohne JavaScript (einseitiges Formular, Fehlerfall, Absenden), `tests/e2e/kontakt.spec.js` das Kontaktformular mit und ohne JavaScript, jeweils Desktop und mobil | Bericht „testsOpen“ dieser Sitzung | nein |
| C7 | Sporadische „Duplicate entry“- und Sperrzustand-Abweichungen bei parallelen Testläufen auf `hvm_test` beobachtet; eigene Testdatenbank je Lauf bei paralleler Agentenarbeit erwägen | Bericht „sec“ dieser Sitzung | nein |
| C8 | `composer test` aktuell nicht stabil grün wegen paralleler Änderungen anderer Agenten an `src/Security`, `src/Http` und der gemeinsamen Testdatenbank; vor Livegang erneut vollständig grün prüfen | Bericht „content“ dieser Sitzung | ja |
| C9 | Kennzahlen aus `docs/pruefbericht.md` stammen aus lokalen `php -S`-Testläufen; vor Livegang gegen echte Staging-/Produktionsumgebung wiederholen | Bericht „tests“ dieser Sitzung | ja |
| C10 | Teilweise erledigt (23.09.2026): Timing bei unbekannten Admin-Konten (Dummy-Hash als Konstante in `src/Security/Password.php`, `tests/Unit/Security/PasswordTest.php`) und Härtung von `TRUSTED_PROXIES` (nur Subnetz des Traefik-Netzes, festes Subnetz für `backend` in `docker-compose.yml`, `docs/betrieb.md` Abschnitt 1.4). Offen: IP-Sperre über mehrere Konten hinweg; auf dem Server Subnetz des Traefik-Netzes ablesen und in `TRUSTED_PROXIES` eintragen | Bericht „tests“ dieser Sitzung | ja |
| C11 | Lighthouse Performance liegt auf allen vier geprüften Seiten bei 99 statt 100 (geringfügiger Diagnosehinweis, über der geforderten Schwelle von 95) | Bericht „testsOpen“ dieser Sitzung | nein |
| C12 | Bei sehr kleinen Darstellungsgrößen (Favicon, 16 bis 24 px) verliert `--hvm-hellgrau` sichtbar an Kontrast; bei Freigabe der Favicon-Variante berücksichtigen | `docs/logo/nachzeichnung-pruefung.md` Abschnitt 5 | nein |
| C13 | Redirect `/datenschutzerklaerung/` auf `/datenschutz/` als „vermutet“ markiert, Status verifizieren | `docs/redirects.md` | nein |
| C14 | Vier Wochen 404-Monitoring nach Livegang einplanen und durchführen | `docs/redirects.md`, MP Abschnitt 13 Phase 7 | nein |
| C15 | erledigt (23.09.2026): CI-Workflow `.github/workflows/ci.yml` (PHP 8.3 mit Lint, Gedankenstrich-Linter und PHPUnit gegen MariaDB 10.11, Playwright unter Node 22, Platzhalter-Bericht nicht blockierend, PII-Prüfung blockierend). Beim ersten Lauf auf GitHub Ergebnis prüfen | diese Sitzung | nein |
| C16 | erledigt (24.09.2026): TOTP-Wiederherstellungscodes (Migration `0011_admin_recovery_codes.sql`, `bin/admin-user.php recovery-codes`), `/.well-known/security.txt`, `/health` als Docker-Healthcheck, vorkomprimierte Assets (`gzip_static`), `bin/deploy-post.sh`, Audit-Job und Dependabot in der CI, Druckansicht (`resources/css/90-druck.css`). Offen: nach dem ersten Deployment `bin/admin-user.php recovery-codes` für bestehende Konten ausführen und Codes offline verwahren | `docs/betrieb.md` Abschnitte 1.5 bis 1.7, `docs/pruefbericht.md` Abschnitt 12 | ja |
| C17 | Nginx-Konfiguration nach den Änderungen (`gzip_static`, `/.well-known/` über PHP, `/llms.txt`, `/assets/img/`) auf Staging mit `nginx -t` und `curl -I` (Content-Encoding, Cache-Control) prüfen; lokal ohne Nginx und Docker nicht ausführbar | `docker/nginx/default.conf` | ja |
| C18 | Externes Monitoring für `/health` einrichten und auf das Feld `datenbank` auswerten (die Antwort ist auch bei Datenbankausfall 200) | `docs/betrieb.md` Abschnitt 4 | nein |
| C19 | Label `abhaengigkeiten` im GitHub-Repository anlegen (von `.github/dependabot.yml` verwendet); Ergebnis des ersten Audit-Jobs prüfen | `.github/dependabot.yml`, `.github/workflows/ci.yml` | nein |
| C20 | Die Wissensübersicht blendet ohne JavaScript die Live-Trefferliste per `u-visually-hidden` aus; `resources/js/search.js` entfernt die Klasse jetzt. Sauberer wäre, die Klasse im Template `templates/pages/wissen.html.twig` wegzulassen (Bereich ist ohnehin `hidden`) | `templates/pages/wissen.html.twig` Zeile 39 | nein |

## D. Inhalte

| Nr. | Punkt | Fundstelle | Blockierend |
|---|---|---|---|
| D1 | Hauseigene Techniker: Aussage bisher nicht bestätigt, bleibt als Platzhalter markiert | `docs/auftraggeber-angaben.md` | nein |
| D2 | Bildnachweise ergänzen, sobald Bilder Dritter eingesetzt werden | `docs/recht-offene-fragen.md` I10 | nein |
| D3 | Weitere Portfolio-Referenzen und Exposé-Adressen nur mit Freigabe veröffentlichen | `docs/redirects.md`, MP Abschnitt 5 | nein |

Hinweis: Einträge, die sowohl eine Geschäftsführungsentscheidung als auch eine rechtliche Bewertung erfordern (zum Beispiel D9, D11, D16, I2, I3, I5, I9 aus `docs/recht-offene-fragen.md`), sind zur besseren Lesbarkeit dort einsortiert, wo die abschließende Entscheidung liegt, und zusätzlich in der jeweils anderen Liste über die Fundstelle auffindbar.
