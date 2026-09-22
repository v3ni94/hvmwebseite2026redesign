# Bestandsaufnahme muellerhv.de

Hausverwaltung Müller GmbH, Relaunch-Vorbereitung
Stand: 23.09.2026, erhoben von außen (öffentlich abrufbare Seiten, Suchmaschinenindex)
Status: Arbeitsgrundlage, unvollständig (siehe Abschnitt 9)

---

## 0. Kritischer Befund vor dem Relaunch

Jede abgerufene Seite gibt oberhalb des Seitenkopfes den Block „Last 1 Records from Properties Table“ aus. Enthalten sind vollständige Leaddatensätze: Anrede, Vor- und Nachname, Telefon, E-Mail, Objektadresse, Baujahr, gewünschter Verwaltungsbeginn, Erstellzeitpunkt sowie interne IDs zu Verwaltungsform und Preisstufen.

Festgestellt am 23.09.2026 auf: Betreuungsgebiete, Verkauf, Vermietung, Impressum, zuvor im Suchindex auch Startseite, Bernau, München. Jüngster sichtbarer Datensatz: ID 1915, angelegt 08.09.2026. Die Ausgabe ist live und von Suchmaschinen indexiert.

Ursache vermutlich: Debug-Ausgabe im Lead-Plugin bzw. Code-Snippet, das in Header oder globalem Hook hängt.

Maßnahmen (Freigabe Geschäftsführung):
1. Ausgabe sofort entfernen (Plugin deaktivieren oder Snippet löschen), Cache leeren
2. Google Search Console: Entfernung veralteter Inhalte für alle betroffenen URLs beantragen
3. Datenschutzverletzung dokumentieren, Meldung nach Art. 33 DSGVO an die LDI NRW prüfen (Orientierung: 72 Stunden ab Kenntnis, zu verifizieren, Vorfrist eintragen)
4. Benachrichtigung der Betroffenen nach Art. 34 DSGVO durch Datenschutzbeauftragten oder Anwalt bewerten lassen

Personenbezogene Daten aus diesem Block sind in dieser Bestandsaufnahme bewusst nicht enthalten.

---

## 1. Technik

| Komponente | Befund |
|---|---|
| CMS | WordPress |
| Entwickler | WEB2MEDIA (Footer-Hinweis) |
| Consent | Real Cookie Banner (devowl.io) |
| Analytics | Site Kit by Google, Versionen 1.182 bis 1.187 auf verschiedenen Seiten (Hinweis auf Caching) |
| Bewertungswidget | Metadaten „ti-site-data“, vermutlich Trustindex (Google-Bewertungen), zu verifizieren |
| SEO | Canonicals und Open-Graph-Tags vorhanden, vermutlich Yoast oder Rank Math |
| Immobilienangebote | eigene Route /ff/immobilien/estates/<UUID>, Such- und Filtermaske, vermutlich FLOWFACT-Anbindung, zu verifizieren |
| Leadspeicherung | eigene Tabelle „Properties“, fortlaufende ID, zuletzt 1915 |
| Performance | wiederholte Timeouts und HTTP 500 bei Start, Monheim, Gutachten, Datenschutz, Portfolio, Exposés |

---

## 2. Seitenstruktur (Hauptmenü)

- Start (/#start)
- Leistungen (/#leistungen)
  - Hausverwaltung (/hausverwaltung/)
  - Vermietung (/vermietung/)
  - Verkauf (/verkauf/)
  - Wertgutachten (/gutachten/)
- Über uns (/#ueberuns)
  - Betreuungsgebiete (/betreuungsgebiete/)
- Kundenstimmen (/#kundenstimmen)
- Häufige Fragen (/#faq)
- CTA: Beratung anfordern (/#beratung)

Footer: Über HVM (Kurztext), Mitgliedschaften (VZIV, IVD mit Logos), Impressum, Datenschutzerklärung, Consent-Links

Weitere bekannte Seiten außerhalb des Menüs:
- Standortseiten: /monheim/, /muenchen/, /bernau-bei-berlin/, /konstanz/ (weitere wahrscheinlich)
- Referenz: /portfolio/mfh-moenchengladbach/
- Exposés: /ff/immobilien/estates/<UUID>

---

## 3. Inhalte je Seite

### 3.1 Startseite
- Headline: Ihre Experten für Hausverwaltung, WEG-Verwaltung, SE-Verwaltung
- Über uns: „Seit 2020 wachsen wir stetig“
- Leistungsblöcke: Persönliche Betreuung, Alles auf einen Blick (Eigentümerportal mit Ticketsystem), Umfassender Schutz (Rahmenverträge Versicherung, Gas, Strom, Hausmeister, Techem), Technische Beratung (Sanierung, energetische Maßnahmen, PV, Heizungswechsel, Dachflächenvermietung, Lademanagement, hauseigene Techniker), 24/7 Notfallservice mit Handwerkerpool
- Kundenstimmen, FAQ, Leadformular

### 3.2 Standortseiten (Muster Bernau, München, Monheim)
- Headline: Ihre Experten für Hausverwaltung, WEG-Verwaltung, SE-Verwaltung in <Ort>
- USP: Mitglied VZIV und IVD, unverbindliches Angebot, transparente Kostenstruktur, nachhaltige Praktiken, keine Aufnahmegebühr
- Leadformular mit Hinweis „möglichst viele Informationen“
- Leistungen: Verwaltung, Vermietung, Verkauf, Gutachten
- Über uns: „Seit 2022 wachsen wir stetig ... in <Ort> und Umgebung“
- Kennzahlen: Einheiten + 680, Experten + 19, Kundenfokus 80 %
- Leistungsblöcke wie Startseite
- Vier Kundenstimmen mit Vorname und Initial (Namen hier bewusst nicht wiedergegeben)
- FAQ (ca. zehn Fragen, identisch auf allen Standortseiten)
- Kontakt im Kopf: <ort>@muellerhv.de, 03043 9733333 (auch bei Monheim und München)

### 3.3 Hausverwaltung (/hausverwaltung/)
Headline „Wir übernehmen Ihre unangenehmen Aufgaben“. Leistungsliste plus zehn Textblöcke: Buchhaltung und Abrechnungswesen, Wirtschaftspläne, Handwerkernetzwerk, technische Betreuung, persönliche Betreuung, Risikoabsicherung, Baumaßnahmen, Konfliktlösung, Versammlungen, Unser Fokus (24/7 Erreichbarkeit).
Bewertung: generischer Ratgebertext ohne HVM-Bezug, fachliche Unschärfen, Tippfehler („Reperaturen“).

### 3.4 Vermietung (/vermietung/)
Headline „Mit uns vermieten Sie sicher!“, allgemeiner Ratgeber in sechs Schritten, danach Immobilienliste (22 Treffer).
Bewertung: richtet sich an Selbstvermieter statt an potenzielle Auftraggeber, kein Leistungsversprechen der HVM.

### 3.5 Verkauf (/verkauf/)
Headline „Wir unterstützen Sie beim erfolgreichen Verkauf Ihrer Immobilie“, Ablauf Wertermittlung, Präsentation, Besichtigung, Kaufvertrag, danach Immobilienliste (23 Treffer).
Hinweis: Aussage „rechtssicherer Kaufvertrag“ ist Notarsache, Formulierung anpassen.

### 3.6 Wertgutachten (/gutachten/)
Nicht abrufbar (HTTP 500). Inhalt offen.

### 3.7 Betreuungsgebiete (/betreuungsgebiete/)
Einleitung mit Sitz Monheim, Hinweis auf Partner und externe Teams in einzelnen Regionen. 13 Standorte:

| Standort | Anschrift | Telefon | E-Mail |
|---|---|---|---|
| Berlin | Wilmersdorfer Str. 122-123, 10627 Berlin | 03043 / 9733333 | berlin@ |
| München | Leopoldstraße 31, 80802 München | 02431 / 9550300 | muenchen@ |
| Monheim am Rhein | Rheinpromenade 13, 40789 Monheim | 02431 / 9550300 | monheim@ |
| Hameln | Basbergstraße 96, 31787 Hameln | 02203 / 2906444 | hameln@ |
| Köln / Bonn | Dülkenstr. 9, 51143 Köln | 02203 / 2906444 | koeln@ |
| Düsseldorf | Grafenberger Allee 277-287, 40237 Düsseldorf | 02125 / 47214777 | duesseldorf@ |
| Hamburg | Baumwall 7, 20459 Hamburg | 02431 / 9550300 | hamburg@ |
| Hannover | Vahrenwalder Str. 289A, 30179 Hannover | 05115 / 4689666 | hannover@ |
| Frankfurt | Poststraße 2-4, 60329 Frankfurt | 06924 / 751355 | frankfurt@ |
| Essen | Rüttenscheider Straße 295, 45131 Essen | 0201 / 89069099 | essen@ |
| Kassel | Fünffensterstraße 2, 34117 Kassel | 02431 9550300 | kassel@ |
| Erkelenz | Gewerbestraße Süd 30, 41812 Erkelenz | 02431 / 9550300 | info@ |
| Konstanz | Lohnerhofstraße 2, 78467 Konstanz | 02125 / 4214777 | konstanz@ |

Auffälligkeiten: Telefonnummern falsch formatiert (z. B. 03043 statt 030 43...), Düsseldorf 02125/47214777 und Konstanz 02125/4214777 weichen voneinander ab, Erkelenz ist nicht mehr Geschäftssitz.

### 3.8 Impressum (/impressum/)
- Hausverwaltung Müller GmbH, c/o Müller Holding AG, Rheinpromenade 13, 40789 Monheim a. Rhein
- HRB 104762, AG Düsseldorf, vertreten durch Timo Müller
- Telefon +49 (0) 2431 / 955 0300, info@muellerhv.de
- Feld „Umsatzsteuer-ID“ enthält eine Steuernummer (hier bewusst nicht wiedergegeben), keine USt-IdNr.
- Bildnachweis: Titelbild Konstanz, Münster Konstanz, © Holger Uwe Schmitt, CC BY-SA 4.0
- Rechtsgrundlage genannt: § 5 TMG (seit 2024 durch § 5 DDG ersetzt, zu verifizieren)
- Hinweis auf EU-OS-Plattform (Plattform wurde 2025 eingestellt, zu verifizieren)
- Teilnahme an Universalschlichtungsstelle Kehl erklärt (prüfen, ob tatsächlich gewollt, da verpflichtend bindend)
- Quelle e-recht24-Generator, robots noindex

### 3.9 Datenschutzerklärung
Nicht abrufbar (Timeout). Muss für den Relaunch ohnehin neu erstellt werden.

---

## 4. Leadstrecke (Ist)

Formularfelder sichtbar: Anzahl der Einheiten, gewünschter Verwaltungsbeginn, Kontaktdaten, Objektadresse, Baujahr.

Felder der Tabelle „Properties“ laut Debug-Ausgabe:

| Feld | Bedeutung |
|---|---|
| ID | fortlaufend |
| Contact Gender | Anrede |
| Contact First Name / Last Name | Name |
| Contact Telephone / Email | Kontakt |
| Contact Street / Zip / City | Kontaktanschrift (meist leer) |
| management_start | gewünschter Verwaltungsbeginn |
| Year of Construction | Baujahr |
| Street / Zip / City | Objektanschrift |
| Source | Quelle (leer, Tracking fehlt) |
| Created At | Zeitstempel |
| managementform_id | Verwaltungsform (WEG, Miet, SE) |
| private_managementcost_id | Preisstufe Wohneinheiten |
| commercial_managementcost_id | Preisstufe Gewerbe |
| parking_managementcost_id | Preisstufe Stellplätze |

Schlussfolgerung: Das Formular berechnet bereits eine Preisindikation über hinterlegte Preisstufen. Diese Logik muss vor dem Relaunch im Code oder in der Datenbank gesichert werden. Das Feld Source ist leer, Kanalzuordnung (Google Ads, organisch, Portal) findet nicht statt.

---

## 5. Kennzahlen und Claims (Ist gegen Realität)

| Aussage Webseite | Realität laut Unterlagen | Handlung |
|---|---|---|
| Seit 2020 (Start) / Seit 2022 (Standorte) | Gründung 04.03.2020 | einheitlich „seit 2020“ |
| + 680 Einheiten | 869 Einheiten, 67 Objekte (Stand 01.07.2026) | aktualisieren |
| + 19 Experten | offen | prüfen |
| Kundenfokus 80 % | nicht erklärbar | streichen |
| Jahrelange lokale Erfahrung in <Ort> | teils Partnerbetreuung | Formulierung wahrheitsgemäß fassen |

---

## 6. Bildbestand (soweit von außen ermittelbar)

| Datei | Verwendung | Hinweis |
|---|---|---|
| /wp-content/uploads/2023/01/Berlin.jpg | OG-Bild Betreuungsgebiete | Lizenz prüfen |
| /wp-content/uploads/2023/01/Verkauf.jpg | OG-Bild Verkauf, 2048 x 2048 | Lizenz prüfen |
| /wp-content/uploads/2024/07/vermietung.webp | OG-Bild Vermietung, 1000 x 1000 | Lizenz prüfen |
| Titelbild Konstanz (Münster) | Standortseite Konstanz | CC BY-SA 4.0, Namensnennung Pflicht |
| HVM-Logo | Kopf, Footer | eigenes Asset, Original aus Skill hvm-ci verwenden |
| VZIV-Logo, IVD-Logo | Footer | Nutzung gemäß Verbandsrichtlinien |
| Standortbilder (13) | Betreuungsgebiete | Dateinamen von außen nicht auslesbar |

Die Bilddateinamen im Seiteninhalt werden vom Abrufwerkzeug nicht ausgegeben. Vollständige Liste nur über Mirror oder uploads-Ordner.

---

## 7. Immobilienangebote (Stand 23.09.2026)

23 Einträge (Verkauf) bzw. 22 (Vermietung) aus Berlin, Bernau, Hückelhoven, Duisburg, Mönchengladbach, Iserlohn, Düsseldorf, Stolberg, Witten, Erkelenz. Darunter drei Kaufangebote in Mönchengladbach (49.000,00 EUR bis 79.000,00 EUR). Datenquelle vermutlich externes Maklersystem, im Relaunch per Schnittstelle oder iFrame einbinden, nicht manuell pflegen.

---

## 8. Externe Einträge mit veralteten Daten (lokales SEO)

Branchenverzeichnisse führen die HVM noch unter Erkelenzer Anschriften (Gewerbestraße Süd 30, Unterwestrich 12, Luxemburger Straße 33) und teils unter dem alten Registereintrag HRB 19321 AG Mönchengladbach. Uneinheitliche Anschriften schwächen das lokale Ranking. Google-Unternehmensprofil und Verzeichnisse auf Monheim vereinheitlichen.

---

## 9. Nicht erfasst (Server instabil, HTTP 500 und Timeouts)

- Startseite vollständig (nur Suchindex-Auszüge)
- Wertgutachten, Datenschutzerklärung, Monheim, Portfolio, Exposé-Detailseiten
- vollständige Liste der Standortseiten und Portfolio-Referenzen
- Bilddateinamen und Alt-Texte
- Formular-Frontend (Pflichtfelder, Einwilligungstext, Weiterleitung nach Absenden)

Empfehlung: erneuter Abruf nachts oder nach Behebung der Serverfehler, alternativ Claude Code mit wget-Mirror von einem Rechner ohne Domainbeschränkung.

---

## 10. Konsequenzen für den Relaunch

1. Datenleck schließen, bevor irgendetwas migriert wird
2. Preisstufenlogik und Tabellenschema der Leads sichern
3. Texte nicht übernehmen, sondern neu schreiben (Ratgebertexte ohne Bezug, fachliche Fehler in der FAQ)
4. Standortseiten konsolidieren: echte Standorte mit echtem Inhalt, Partnerregionen klar kennzeichnen
5. FAQ-Seite neu, fachlich korrekt, mit strukturierten Daten (FAQPage)
6. Kennzahlen aus Objektstammdaten speisen, nicht hart codieren
7. Impressum und Datenschutz neu, Steuernummer entfernen, USt-IdNr. nur wenn vorhanden
8. Kundenstimmen nur mit belegbarer Herkunft, bevorzugt echte Google-Bewertungen
9. Quellen-Tracking (Source, UTM) in die Leadstrecke aufnehmen
