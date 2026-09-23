# Rechtliche offene Fragen zum Relaunch

Stand: 23.09.2026. Status: Entwurf zur Klärung vor dem Livegang.
Betrifft die Pflichtseiten `/impressum/`, `/datenschutz/` und `/barrierefreiheit/` (Templates `templates/pages/impressum.html.twig`, `datenschutz.html.twig`, `barrierefreiheit.html.twig`) sowie angrenzende Formulartexte.
Grundlage: Masterprompt Abschnitt 2, 9 und 10, `docs/bestandsaufnahme-muellerhv-de.md` Abschnitt 3.8, `docs/architektur.md`, `docs/auftraggeber-angaben.md`.

Die folgenden Punkte sind keine Rechtsberatung, sondern eine Sammlung der Fragen, die vor dem Livegang von den genannten Stellen zu entscheiden sind. Normverweise sind als Arbeitsstand zu verstehen und ausdrücklich zu verifizieren. Jeder Punkt ist auf der Seite als sichtbarer Platzhalter in eckigen Klammern gekennzeichnet.

Zuständigkeiten: **RA** Rechtsanwalt (IT-Recht, Gewerberecht), **DSB** Datenschutzbeauftragter oder Rechtsanwalt mit Schwerpunkt Datenschutz, **GF** Geschäftsführung, **StB** Steuerberater, **IT** technischer Betrieb.

## 1. Impressum

| Nr. | Frage | Zuständig | Hinweis |
|---|---|---|---|
| I1 | Rechtsgrundlage der Anbieterkennzeichnung: Die Altseite nennt § 5 TMG. Nach unserem Kenntnisstand wurde das TMG 2024 durch das Digitale-Dienste-Gesetz (DDG) abgelöst, die Seite nennt daher „Angaben gemäß § 5 DDG“. | RA | [Rechtsgrundlage vor Livegang verifizieren] |
| I2 | Gewerberechtliche Erlaubnis: Die HVM verwaltet Wohnimmobilien und vermittelt Immobilien. Liegt eine Erlaubnis nach § 34c GewO vor (für welche Tätigkeiten), welche Behörde hat sie erteilt und ist sie zuständige Aufsichtsbehörde? Welche Angaben sind im Impressum aufzunehmen? | RA, GF | [Erlaubnis nach § 34c GewO und zuständige Behörde ergänzen] |
| I3 | Berufshaftpflichtversicherung: Besteht eine Pflicht zur Angabe im Impressum oder in anderer Form (zum Beispiel aufgrund der Erlaubnis nach § 34c GewO oder aufgrund allgemeiner Informationspflichten für Dienstleister)? Falls ja: Versicherer, Anschrift, räumlicher Geltungsbereich. | RA, GF | [Angaben zur Berufshaftpflichtversicherung prüfen] |
| I4 | USt-IdNr.: Ist eine Umsatzsteuer-Identifikationsnummer vorhanden? Die Altseite zeigte im Feld „Umsatzsteuer-ID“ eine Steuernummer. Eine Steuernummer wird auf der neuen Seite nicht veröffentlicht. | GF, StB | [USt-IdNr. ergänzen, falls vorhanden], Pflege in `config/unternehmen.php` (`ust_id`) |
| I5 | Verbraucherstreitbeilegung: Die Altseite erklärte die Teilnahme an Streitbeilegungsverfahren vor der Universalschlichtungsstelle. Eine solche Erklärung ist bindend. Soll die HVM teilnehmen oder nicht? Besteht eine Pflicht zur Information (abhängig unter anderem von der Zahl der Beschäftigten und davon, ob Verträge mit Verbrauchern geschlossen werden)? Welche Formulierung ist zu verwenden? | RA, GF | [Aussage zur Teilnahme an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle vor Livegang prüfen] |
| I6 | EU-Plattform zur Online-Streitbeilegung (OS-Plattform): Laut Bestandsaufnahme wurde die Plattform 2025 eingestellt. Die neue Seite enthält deshalb keinen Link. Ist ein Hinweis weiterhin erforderlich oder ausdrücklich zu entfernen, auch in AGB und Verträgen? | RA | [verifizieren] |
| I7 | Verantwortlicher für redaktionelle Inhalte: Der Bereich Wissen enthält redaktionelle Beiträge. Ist eine Angabe nach dem Medienstaatsvertrag (nach unserem Kenntnisstand § 18 Abs. 2 MStV) erforderlich? Falls ja: Name und Anschrift der verantwortlichen Person. | RA | [Angabe für redaktionelle Inhalte nach Medienstaatsvertrag prüfen, Name und Anschrift ergänzen] |
| I8 | Anschrift: Die Altseite führte „c/o Müller Holding AG“. `config/unternehmen.php` und der Masterprompt nennen Rheinpromenade 13, 40789 Monheim am Rhein ohne c/o-Zusatz. Ist die Anschrift ohne Zusatz ladungsfähig und so zu verwenden? | GF, RA | [bestätigen] |
| I9 | Mitgliedschaften VZIV und IVD: Die Nennung stammt aus dem Masterprompt. Bestehen daraus berufsrechtliche Informationspflichten (zum Beispiel Verweis auf Standesregeln)? Aktuelle Mitgliedschaft bestätigen. | RA, GF | [bestätigen] |
| I10 | Bildnachweise: Das Konstanz-Bild der Altseite (CC BY-SA 4.0) wird nicht verwendet. Der Abschnitt ist vorbereitet. Bei jedem neuen Bild Dritter ist der Nachweis zu ergänzen. Die Kartengrundlage (Natural Earth) ist gemeinfrei, der Nachweis erfolgt freiwillig. | GF | [Bildnachweise ergänzen, sobald Bilder Dritter eingesetzt werden] |
| I11 | Haftungsausschlüsse aus Generatoren (Haftung für Inhalte und Links) wurden bewusst nicht übernommen. Stattdessen steht ein kurzer Hinweis, dass die Inhalte der allgemeinen Information dienen und keine Rechts- oder Steuerberatung sind. Ist das so gewünscht? | RA | |
| I12 | Indexierung: Die Altseite setzte für das Impressum `noindex`. Die neue Seite nimmt das Impressum in die Sitemap auf. Beibehalten oder ändern? | GF | |
| I13 | Freigabe des Impressums insgesamt. | GF nach RA | [Freigabe Impressum] |

## 2. Datenschutzerklärung

Die Seite ist ein Gerüst auf Grundlage der tatsächlich umgesetzten Technik (Stand Code: Migrationen 0001 bis 0010, `Hvm\Service\Attribution`, `Hvm\Http\Session`, `Hvm\Security\RateLimiter`, `Hvm\Security\SpamGuard`, `Hvm\Service\WebhookService`, `docs/betrieb.md`). Außerhalb der Produktion zeigt sie oben den Hinweis „Gerüst, finale Fassung durch Datenschutzbeauftragten oder Rechtsanwalt“.

| Nr. | Frage | Zuständig | Hinweis |
|---|---|---|---|
| D1 | Datenschutzbeauftragter: Ist einer benannt oder benennungspflichtig? Falls ja: Name oder Funktion und Kontakt. | GF, DSB | [Datenschutzbeauftragten mit Kontaktdaten ergänzen, falls benannt] |
| D2 | Hosting: Vertragspartner des Servers (laut `docs/betrieb.md` IONOS Dedicated Server), genaue Firmierung, Serverstandort, Vertrag zur Auftragsverarbeitung nach Art. 28 DSGVO. | GF, IT | [Vertrag zur Auftragsverarbeitung bestätigen], [Serverstandort bestätigen], [Vertragspartner und Rechtsform verifizieren] |
| D3 | Server-Logfiles: Welche Felder protokollieren Traefik und Nginx tatsächlich (IP-Adresse vollständig oder gekürzt, User-Agent, Referrer)? Speicherdauer und Rotation festlegen (auch `docs/betrieb.md`). | IT, DSB | [Speicherdauer der Server-Logfiles festlegen], [Umfang der Protokollierung mit der Konfiguration von Nginx und Traefik abgleichen] |
| D4 | Sitzungscookie `hvm_sid`: nur bei Formularen und Admin, Sitzungscookie ohne Ablaufdatum, Leerlaufzeit laut `.env` (`SESSION_IDLE_TIMEOUT`, Vorgabe 1800 Sekunden). Bestätigen, dass keine Einwilligung erforderlich ist, und Vorschrift im TDDDG zur Ausnahme für unbedingt erforderliche Speicherung verifizieren. | DSB | [Vorschrift des TDDDG zur Ausnahme für unbedingt erforderliche Speicherung verifizieren], [Dauer der Sitzung bei Inaktivität bestätigen] |
| D5 | Kanalzuordnung: utm-Parameter, Klickkennung `gclid`, Landing-Pfad und Referrer-Host werden ohne Cookies über die URL weitergereicht und mit der Anfrage gespeichert. Wird Google Ads eingesetzt? Wird die `gclid` an Google zurückgemeldet (Offline-Conversion)? Falls ja, ist die Weitergabe gesondert zu bewerten, gegebenenfalls einwilligungspflichtig. Rechtsgrundlage für die Speicherung prüfen. | GF, DSB | [Einsatz von Google Ads und Weitergabe der Klickkennung klären] |
| D6 | Missbrauchsschutz: Honeypot, Zeitprüfung, Rate Limiting mit HMAC-Prüfwert der IP-Adresse. Löschfrist der Einträge in `rate_limits` und `admin_login_attempts` festlegen und technisch umsetzen. | IT, DSB | [Löschfrist der Zählwerte festlegen] |
| D7 | Angebotsformular: Rechtsgrundlage (Vorschlag Art. 6 Abs. 1 lit. b DSGVO), Datensparsamkeit der Felder bestätigen, Text des Datenschutzhinweises im Formular und dessen Versionierung (`consent_text_version`) freigeben. Die Checkbox „Ich habe den Datenschutzhinweis gelesen“ ist keine Einwilligung; klären, ob sie beibehalten wird. | DSB | [Rechtsgrundlage prüfen], im Formular [Freigabe Datenschutztext] |
| D8 | Kontaktformular: wird parallel in Welle 2a gebaut. Felder mit der Erklärung abgleichen (Anliegen, Name, E-Mail, Telefon, Nachricht, Region). Klären, ob Kontaktanfragen ebenfalls an n8n übermittelt werden; dann Abschnitt „Interne Weiterverarbeitung“ ergänzen. | IT, DSB | |
| D9 | Bewerbungen: Rechtsgrundlage für Bewerberdaten. Die Anwendbarkeit von § 26 BDSG ist nach neuerer Rechtsprechung des EuGH umstritten; die Seite nennt deshalb nur „Anbahnung eines Beschäftigungsverhältnisses“. Aufbewahrungsfrist nach Absage festlegen (unter Berücksichtigung möglicher Ansprüche nach dem AGG) und technisch umsetzen. Zugriffskreis im Unternehmen festlegen. | DSB, RA, GF | [Rechtsgrundlage für Bewerberdaten prüfen], [Aufbewahrungsfrist für Bewerbungsunterlagen festlegen] |
| D10 | E-Mail-Versand: SMTP-Dienstleister benennen (Zugang in `.env` `MAIL_*`), Vertrag zur Auftragsverarbeitung, Serverstandort. | GF, IT | [SMTP-Dienstleister ergänzen], [Vertrag zur Auftragsverarbeitung mit dem E-Mail-Dienstleister bestätigen] |
| D11 | Automatisierung n8n: Wer betreibt die Instanz (selbst gehostet auf eigenem Server oder n8n Cloud)? Standort? Welche Daten werden übertragen und wohin leitet n8n sie weiter (zum Beispiel CRM, Immoware24, E-Mail)? Jeder weitere Empfänger ist in der Erklärung zu nennen. | GF, IT, DSB | [Betrieb und Standort von n8n klären, gegebenenfalls Auftragsverarbeiter benennen] |
| D12 | Drittlandübermittlung: für alle Dienstleister ausschließen oder benennen. | DSB | [für alle Dienstleister verifizieren] |
| D13 | Speicherdauer: Aufbewahrungsfrist für Anfragen ohne Vertragsschluss festlegen (`LEAD_RETENTION_DAYS`, Löschlauf `bin/retention.php` laut Architektur geplant, noch nicht vorhanden), handels- und steuerrechtliche Fristen bei Vertragsschluss bestätigen, Aufbewahrung der verschlüsselten Datenbanksicherungen (`BACKUP_KEEP`, Vorgabe 14 Sicherungen, zusätzliche Kopie außerhalb des Servers laut `docs/betrieb.md` offen). | GF, DSB, StB | [Aufbewahrungsfrist für Anfragen festlegen], [Fristen durch Steuerberater bestätigen], [Aufbewahrungsdauer der Datensicherungen festlegen] |
| D14 | Aufsichtsbehörde: Die Seite nennt die Landesbeauftragte für Datenschutz und Informationsfreiheit Nordrhein-Westfalen. Bezeichnung und gegebenenfalls Kontaktdaten verifizieren. | DSB | [Bezeichnung der Aufsichtsbehörde vor Livegang verifizieren] |
| D15 | Reichweitenmessung: Derzeit keine. Falls gewünscht, nur selbst gehostet und cookielos (MP 9), dann Erklärung ergänzen. | GF | [Analyse-Werkzeug festlegen] |
| D16 | Altdaten: Übernahme von Leads aus der Altdatenbank (`legacy_id`, Import laut Architektur geplant) und das Datenleck der Altseite (Phase 0, laut `docs/auftraggeber-angaben.md` in Arbeit). Klären, ob Informations- oder Meldepflichten bestehen und ob die Übernahme der Altdaten zulässig ist. | DSB, RA, GF | [klären] |
| D17 | Verzeichnis von Verarbeitungstätigkeiten und Auftragsverarbeitungsverträge für alle oben genannten Dienste aktualisieren. | DSB | |
| D18 | Stand-Datum bei Freigabe aktualisieren und die Erklärung bei jeder Änderung an Formularen oder Diensten anpassen. | GF | [Stand bei Freigabe aktualisieren] |
| D19 | Freigabe der Datenschutzerklärung insgesamt. | GF nach DSB | [Freigabe Datenschutzerklärung] |

## 3. Erklärung zur Barrierefreiheit

| Nr. | Frage | Zuständig | Hinweis |
|---|---|---|---|
| B1 | Gesetzliche Pflicht: Die Seite gibt eine freiwillige Erklärung ab und trifft keine Aussage zu einer Pflicht. Prüfen, ob für die Webseite oder einzelne Funktionen (zum Beispiel Formulare mit Vertragsanbahnung gegenüber Verbrauchern) Pflichten aus dem Barrierefreiheitsstärkungsgesetz (BFSG) oder anderen Vorschriften bestehen, einschließlich möglicher Ausnahmen für Kleinstunternehmen. Falls eine Pflicht besteht: vorgeschriebene Inhalte der Erklärung und Angaben zur zuständigen Marktüberwachungsbehörde oder zum Durchsetzungsverfahren. | RA | [verifizieren], [Angaben zu Schlichtungs- oder Durchsetzungsverfahren durch Rechtsanwalt prüfen und verifizieren] |
| B2 | Stand der Vereinbarkeit und Prüfmethode: nach einer Prüfung (automatisiert mit axe-core und manuell mit Tastatur und Screenreader, gegebenenfalls durch Dritte) eintragen. | IT, GF | [Stand der Vereinbarkeit nach Prüfung ausfüllen], [Prüfmethode nach Prüfung ausfüllen] |
| B3 | Bekannte Einschränkungen nach der Prüfung ergänzen, insbesondere Dokumente zum Herunterladen (PDF). | IT | [weitere Einschränkungen nach Prüfung ergänzen] |
| B4 | Eigentümerportal: externes System, von der Erklärung ausgenommen. Barrierefreiheit beim Anbieter erfragen. | GF | |
| B5 | Datum der letzten Überprüfung nach Prüfung eintragen, Überprüfung mindestens jährlich einplanen. | IT | [Datum nach Prüfung ausfüllen] |

## 4. Angrenzende Punkte außerhalb der Pflichtseiten

- Datenschutzhinweis im Angebotsformular (`templates/partials/forms/angebot/schritt-absenden.html.twig`) und in der Eingangsbestätigung (`templates/emails/lead-bestaetigung.*`) mit der finalen Datenschutzerklärung abstimmen.
- Redirect `/datenschutzerklaerung/` auf `/datenschutz/` ist in `config/redirects.php` als nicht verifiziert markiert.
- Footer-Links heißen „Datenschutz“ und „Barrierefreiheit“ (`config/navigation.php`), die Seitenüberschriften „Datenschutzerklärung“ und „Erklärung zur Barrierefreiheit“. Die Linktexte sind eindeutig, eine Angleichung ist optional.
- Aussagen zu Rahmenverträgen, 24/7-Notdienst und Eigentümerportal sind laut `docs/auftraggeber-angaben.md` bestätigt. Werbliche Aussagen auf anderen Seiten sind wettbewerbsrechtlich unkritisch zu halten; eine Durchsicht durch den RA vor dem Livegang wird empfohlen.
