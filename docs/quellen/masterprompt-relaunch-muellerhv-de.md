# Masterprompt: Relaunch muellerhv.de

Für Claude Code. Diese Datei vollständig lesen, bevor Code entsteht.
Begleitdateien im Projektordner `/docs`:
- `bestandsaufnahme-muellerhv-de.md` (Ist-Zustand der alten Seite)
- `Logo_HVM.jpg` (offizielles Logo, 1320 x 1143 px)

---

## 1. Rolle und Auftrag

Du bist Senior Full-Stack-Entwickler und Webdesigner. Du baust die Webseite der Hausverwaltung Müller GmbH (HVM) vollständig neu: eigenes PHP, eigene Datenbank, kein WordPress, kein Pagebuilder.

Ziele in dieser Reihenfolge:
1. Qualifizierte Verwaltungsanfragen (Leads) von WEG-Gemeinschaften, Eigentümern und Investoren gewinnen und strukturiert in der Datenbank speichern
2. Fachliche Kompetenz belegen, insbesondere über eine neue Wissens- und FAQ-Seite
3. Bestehende Mieter und Eigentümer schnell zum richtigen Kanal führen (Portal, Notfall, Schadensmeldung, Kontakt)
4. Rechtssicher, schnell, barrierearm, datenschutzkonform

Arbeitsweise:
- Arbeite in Phasen (Abschnitt 13). Nach jeder Phase kurzer Bericht: Was ist fertig, was ist offen, welche Entscheidung brauche ich.
- Erfinde keine Fakten, Zahlen, Telefonnummern, Preise, Bewertungen, Adressen oder Rechtsangaben. Fehlt etwas, setze einen sichtbaren Platzhalter in eckigen Klammern, z. B. `[Telefonnummer ergänzen]`, und liste ihn in `/docs/offene-punkte.md`.
- Frag nach, bevor du Architekturentscheidungen triffst, die in Abschnitt 4 nicht festgelegt sind.

---

## 2. Unternehmensdaten (verbindlich, exakt so verwenden)

- Firma: Hausverwaltung Müller GmbH
- Anschrift Hauptsitz: Rheinpromenade 13, 40789 Monheim am Rhein
- Registergericht: Amtsgericht Düsseldorf, HRB 104762
- Geschäftsführer: Timo Müller
- Gründung: 04.03.2020 (Formulierung „seit 2020“)
- Mitgliedschaften: VZIV (Verein Zertifizierter ImmobilienVerwalter e. V.), IVD
- Bestand: 869 Verwaltungseinheiten in 67 Objekten, Stand 01.07.2026. Kennzahlen nicht hart codieren, sondern in `config/kennzahlen.php` mit Stichtag pflegen und mit Stichtag anzeigen.
- Leistungsarten: WEG-Verwaltung, Mietverwaltung, Sondereigentumsverwaltung (SE-Verwaltung), Vermietung, Verkauf, Wertgutachten
- Zentrale E-Mail: info@muellerhv.de
- Telefon: `[zentrale Telefonnummer bestätigen]` (alte Seite nennt 02431 9550300, Aktualität prüfen)
- USt-IdNr.: `[USt-IdNr. ergänzen, falls vorhanden]`. Niemals eine Steuernummer veröffentlichen.

---

## 3. Design: extrem modern, strikt im HVM-CI

### 3.1 Grundhaltung
Klar, ruhig, hochwertig. Referenzniveau: moderne Architektur- und Asset-Management-Seiten, nicht klassische Hausverwaltungs-Templates. Viel Weißraum, große Typografie, präzise Raster, wenige, dafür starke Akzente. Kein Stockfoto-Kitsch (Handschlag, Schlüsselübergabe, lachende Familie).

### 3.2 Farben (ausschließlich diese, als CSS Custom Properties)

| Token | Hex | Einsatz |
|---|---|---|
| `--hvm-orange` | #E6A83C | Akzent, Primärbutton-Fläche, Fokusring, aktive Zustände, Kennlinien-Segment |
| `--hvm-ink` | #1A1A1A | Fließtext, Wortmarke, dunkle Sektionen (Hero, Footer, Kennzahlenband) |
| `--hvm-anthrazit` | #87888A | Sekundärtext nur auf dunklem Grund oder ab 24 px, Icons, Linien |
| `--hvm-mittelgrau` | #9C9D9F | Trennlinien, inaktive Elemente |
| `--hvm-hellgrau` | #D7D8DA | Flächen, Kartenränder, Tabellenzeilen |
| `--hvm-umriss` | #ECECEC | Hausumriss-Motiv, Flächen, Hintergrund |
| `--hvm-weiss` | #FFFFFF | Grundfläche |

Kontrastregeln (WCAG 2.2 AA, verbindlich):
- Orange niemals als Textfarbe auf Weiß (Kontrast unzureichend). Orange nur als Fläche oder auf `--hvm-ink`.
- Primärbutton: Fläche Orange, Text `--hvm-ink`.
- Anthrazit #87888A auf Weiß nur für große Schrift ab 24 px oder dekorativ. Normaler Sekundärtext auf Weiß: `--hvm-ink` mit reduzierter Schriftstärke, nicht mit Grau.
- Kontraste im Build automatisiert prüfen (z. B. axe oder pa11y) und im Bericht ausweisen.

Dark Mode: optional über `prefers-color-scheme`, Basis `--hvm-ink`, Akzent Orange. Keine zusätzlichen Farben einführen.

### 3.3 Typografie
- Schrift gemäß CI: `system-ui, "Helvetica Neue", Helvetica, Arial, sans-serif`. Keine zusätzlichen Webfonts ohne Freigabe.
- Die Wortmarke kombiniert „HAUSVERWALTUNG“ in leichter Strichstärke mit „MÜLLER“ in Fett. Diese Paarung leicht/fett ist das typografische Leitmotiv: Headlines mit einem fett gesetzten Schlüsselwort, Rest regulär oder leicht.
- Fluid Type mit `clamp()`. Display-Headlines 48 bis 96 px, enges Tracking (−0,02em), Zeilenhöhe 1,05. Fließtext 17 bis 18 px, Zeilenhöhe 1,6, maximal 70 Zeichen Zeilenlänge.
- Versalien nur für kurze Labels (Eyebrows) mit erweitertem Tracking.

### 3.4 Signaturelemente aus dem Logo
Das Logo besteht aus H, V, M in abgestuften Grautönen, einem orangefarbenen Diagonalkeil zwischen V und M und einem offenen Hausumriss. Daraus entstehen die Gestaltungselemente:

1. **HVM-Kennlinie**: Band aus vier Segmenten mit diagonalen Übergängen: Anthrazit 0 bis 40 %, Mittelgrau 40 bis 60 %, Orange 60 bis 67,5 %, Hellgrau 67,5 bis 100 %. Einsatz: oberer Seitenrand (3 px bis 4 px), Footer-Oberkante, Fortschrittsbalken im Angebotsformular, Scroll-Fortschritt auf Wissensartikeln. Umsetzung als CSS `linear-gradient` mit harten Stopps plus `clip-path` für die Schrägen, keine Bilder.
2. **Orangekeil**: die Diagonale aus dem Logo als wiederkehrendes Motiv, z. B. als schräger Sektionsübergang, als Hover-Wischeffekt auf Karten, als Marker vor Eyebrow-Labels. Winkel aus dem Logo übernehmen und als Token `--hvm-keil-winkel` definieren.
3. **Hausumriss**: offener Umriss in `--hvm-umriss`, groß, angeschnitten über den Sektionsrand hinaus, als Hintergrundmotiv im Hero und auf der Kontaktseite. Als Inline-SVG mit `stroke`, animierbar über `stroke-dashoffset` (zeichnet sich beim ersten Laden einmal nach).
4. **Abgestufte Grautöne**: Karten und Leistungsraster greifen die Stufung H dunkel, V mittel, M hell auf, z. B. als Hintergrundabstufung in einem Bento-Grid.

### 3.5 Layout und Komponenten
- 12-Spalten-Raster, Container max. 1280 px, großzügige Sektionsabstände (96 bis 160 px Desktop).
- Hero: dunkel (`--hvm-ink`), große Headline, ein klarer Primär-CTA „Angebot anfordern“ und ein Sekundär-CTA „Verwalter wechseln“, Hausumriss angeschnitten im Hintergrund, Kennlinie am oberen Rand.
- Kennzahlenband: Einheiten, Objekte, Gründungsjahr, Mitgliedschaften, mit Stichtag. Zähler-Animation nur einmal, respektiert `prefers-reduced-motion`.
- Leistungen als Bento-Grid mit Graustufen-Karten, Hover mit Orangekeil-Wischeffekt.
- Prozessdarstellung „So läuft der Verwalterwechsel“ als horizontale Zeitleiste mit Kennlinie als Verbindungsstrich.
- Sticky Header, beim Scrollen kompakt, mit Glas-Effekt nur wenn Kontrast gesichert ist (`backdrop-filter` mit ausreichend deckendem Hintergrund).
- Mobile: Bottom-Bar mit drei Aktionen (Angebot, Anrufen, Notfall).

### 3.6 Bewegung
- Scroll-getriebene Animationen über CSS `animation-timeline: view()` mit Fallback, View Transitions API für Seitenwechsel.
- Dezent: Einblenden mit 8 px bis 16 px Versatz, 300 bis 500 ms, einheitliche Easing-Kurve als Token.
- Alles abschaltbar über `prefers-reduced-motion: reduce`.
- Kein Parallax-Übermaß, keine Autoplay-Videos mit Ton, keine Karussells für Kerninhalte.

### 3.7 Bilder
- Bilder der alten Seite dürfen genutzt werden, sofern Lizenz geklärt ist. Vor Übernahme je Bild Lizenzstatus in `/docs/bildinventar.md` erfassen (eigenes Foto, Stock mit Lizenz, CC mit Namensnennung, ungeklärt). Ungeklärte Bilder nicht verwenden.
- Titelbild Konstanz ist CC BY-SA 4.0 (Holger Uwe Schmitt): nur mit Namensnennung und Lizenzhinweis.
- Bildsprache: echte Objekte aus dem Bestand, Architekturdetails, Treppenhäuser, Fassaden, mit einheitlichem Grading (leicht entsättigt, warme Lichter passend zum Orange).
- Alle Bilder als AVIF und WebP mit `srcset`, `loading="lazy"` außer Hero, feste Seitenverhältnisse gegen Layout-Sprünge.

### 3.8 Logo
- Das Logo liegt nur als JPG vor. Für das Web wird eine SVG benötigt. Vorgehen: zuerst Originalvektor beim früheren Dienstleister anfordern (`[Vektordatei Logo anfordern]`). Falls nicht verfügbar, technische Nachzeichnung als SVG aus der geometrischen Form, pixelgenau gegen das JPG geprüft, mit Freigabe der Geschäftsführung vor Livegang.
- Seitenverhältnis 1320 x 1143 strikt einhalten, Schutzraum mindestens die Höhe des M-Querstrichs. Logo nicht einfärben, nicht verzerren, nicht animieren außer dezentem Einblenden.
- Varianten: Vollversion (Haus plus HVM plus Wortmarke), Kurzform HVM für Favicon und kompakten Header, negative Variante für dunkle Flächen nur, wenn Freigabe vorliegt.

---

## 4. Technik (festgelegt)

- PHP 8.3, eigene schlanke Struktur ohne Framework-Zwang. Erlaubt: Composer mit wenigen, gepflegten Paketen (Routing, Templating mit Auto-Escaping, Mail, Validierung). Begründe jedes Paket.
- Templating: serverseitig gerendertes HTML, progressive Enhancement. JavaScript nur wo nötig, ohne schweres Framework. Alpine.js oder Vanilla JS zulässig.
- CSS: eigene Design-Tokens in CSS Custom Properties, moderne Features (Container Queries, `:has()`, Cascade Layers, `clamp()`). Kein Bootstrap. Tailwind nur nach Rückfrage.
- Datenbank: MariaDB, eigene Datenbank und eigener Benutzer mit minimalen Rechten, Migrationen versioniert als SQL-Dateien.
- Deployment: Docker Compose (PHP-FPM plus Nginx oder FrankenPHP) hinter dem bestehenden Traefik auf dem IONOS Dedicated Server. Staging unter `neu.muellerhv.de`, mit HTTP Basic Auth und `noindex`.
- Konfiguration über `.env`, niemals Zugangsdaten im Repository.
- Git-Repository mit sauberer Commit-Historie, README mit Setup, Deployment und Rollback.

---

## 5. Seitenstruktur (neu)

| Seite | Zweck | Kern-CTA |
|---|---|---|
| Start | Positionierung, Kennzahlen, Leistungen, Prozess, Stimmen, FAQ-Auszug | Angebot anfordern |
| WEG-Verwaltung | Leistungsumfang, Abgrenzung, Ablauf | Angebot anfordern |
| Mietverwaltung | Leistungsumfang für Vermieter und Investoren | Angebot anfordern |
| SE-Verwaltung | Sondereigentumsverwaltung für Kapitalanleger | Angebot anfordern |
| Verwalterwechsel | wichtigste Landingpage: Ablauf, Zeitplan, Unterlagenübergabe, Beschlussfassung | Wechsel anfragen |
| Vermietung | Leistung der HVM für Eigentümer (nicht Ratgeber für Selbstvermieter), Immobilienliste | Vermietung beauftragen |
| Verkauf | Leistung, Ablauf, Immobilienliste | Bewertung anfragen |
| Wertgutachten | Leistung, Anlässe, Ablauf | Gutachten anfragen |
| Wissen und FAQ | Wissensartikel und FAQ nach Zielgruppen (Abschnitt 7) | kontextbezogen |
| Betreuungsgebiete | Übersicht Regionen mit klarer Kennzeichnung eigene Präsenz oder Partnerbetreuung, Karte ohne Google-Tracking | Anfrage mit Region |
| Referenzen | Objekttypen und Fallbeispiele, nur mit Freigabe der Eigentümer | Angebot anfordern |
| Über uns | Geschichte seit 2020, Team, Mitgliedschaften, Werte | Kontakt |
| Karriere | Stellen und Ausbildung | Bewerbung |
| Service für Mieter und Eigentümer | Portal-Login, Schadensmeldung, Notfall, Formulare, Erreichbarkeit | Portal öffnen |
| Notfall | 24/7-Nummer, was ist ein Notfall, was nicht | Anrufen |
| Kontakt | Hauptsitz, Formular, Anfahrt | Nachricht senden |
| Impressum, Datenschutz, Barrierefreiheitserklärung | Pflichtseiten | keiner |

Standortseiten: Die alten, fast identischen Seiten je Stadt werden nicht nachgebaut. Stattdessen eine Seite je Region nur dort, wo echter regionaler Inhalt vorliegt (verwaltete Objekte, Ansprechpartner, regionale Besonderheiten). Aussagen wie „jahrelange Erfahrung in <Ort>“ nur, wenn belegbar. Alle alten URLs per 301 auf die passende neue Seite umleiten (Redirect-Map in `/docs/redirects.md`).

Immobilienangebote: Die bestehende Anbindung unter `/ff/immobilien/estates/<UUID>` kommt aus einem externen Maklersystem (vermutlich FLOWFACT, zu verifizieren). Nicht nachbauen, sondern Schnittstelle klären und einbinden. Bis dahin `[Datenquelle Immobilienangebote klären]`.

---

## 6. Leadstrecke

### 6.1 Angebotsformular (mehrstufig)
Schritte mit Kennlinie als Fortschrittsanzeige:
1. Verwaltungsart: WEG, Miet, SE
2. Objekt: Anschrift (Straße, PLZ, Ort), Baujahr, Anzahl Wohneinheiten, Gewerbeeinheiten, Stellplätze
3. Zeitpunkt: gewünschter Verwaltungsbeginn, aktueller Verwalter vorhanden ja oder nein
4. Kontakt: Anrede, Vorname, Nachname, E-Mail, Telefon, Rolle (Eigentümer, Beirat, Investor, sonstige), Freitext
5. Zusammenfassung mit Datenschutzhinweis und Absenden

Anforderungen:
- Ohne JavaScript bedienbar (Fallback als einseitiges Formular).
- Validierung serverseitig verpflichtend, clientseitig ergänzend.
- Zwischenstand clientseitig nur im Speicher, nicht im Browser-Storage mit personenbezogenen Daten.
- Danke-Seite mit nächsten Schritten und realistischer Antwortzeit `[Antwortzeit festlegen]`.

### 6.2 Preisindikation
Die alte Seite ordnet jedem Lead Preisstufen zu (`private_managementcost_id`, `commercial_managementcost_id`, `parking_managementcost_id`, `managementform_id`). Die Preistabellen sind nicht bekannt. Keine Preise erfinden. Umsetzung:
- Tabelle `price_tiers` anlegen (Verwaltungsart, Einheitentyp, Staffel von bis Einheiten, Preis netto je Einheit und Monat, gültig ab).
- Befüllung erst nach Export aus der alten Datenbank oder Vorgabe durch die Geschäftsführung.
- Anzeige im Formular nur, wenn per Konfiguration freigeschaltet, immer als unverbindliche Indikation mit Hinweis auf individuelles Angebot.

### 6.3 Datenmodell (Mindestumfang, Migration der Altfelder)

Tabelle `leads`:
- `id`, `uuid`, `created_at`, `updated_at`
- `management_form` (weg, miet, se)
- `contact_salutation`, `contact_first_name`, `contact_last_name`, `contact_email`, `contact_phone`, `contact_role`
- `contact_street`, `contact_zip`, `contact_city` (optional)
- `object_street`, `object_zip`, `object_city`, `year_of_construction`
- `units_residential`, `units_commercial`, `units_parking`
- `management_start`, `has_current_manager`
- `message`
- `price_tier_residential_id`, `price_tier_commercial_id`, `price_tier_parking_id` (Fremdschlüssel)
- `source`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `landing_page`, `referrer`
- `consent_text_version`, `consent_at`
- `status` (neu, kontaktiert, angebot, gewonnen, verloren, spam), `assigned_to`, `notes`
- `legacy_id` (ID aus der alten Tabelle „Properties“)

Weitere Tabellen: `lead_events` (Statuswechsel mit Zeitstempel und Benutzer), `price_tiers`, `admin_users`, `contact_requests` (allgemeines Kontaktformular), `job_applications` (nur Metadaten, Dateien verschlüsselt außerhalb des Webroots).

Import Altbestand: Skript `bin/import-legacy-leads.php`, liest einen SQL- oder CSV-Export der alten Tabelle, mappt Felder, setzt `legacy_id`, schreibt Importprotokoll. Export wird von der Geschäftsführung bereitgestellt `[Export alte Lead-Tabelle bereitstellen]`.

### 6.4 Verarbeitung nach Eingang
1. Speichern in MariaDB (Transaktion).
2. Benachrichtigung per E-Mail an `[Empfängeradresse Leads festlegen]` ohne vollständige personenbezogene Daten im Betreff.
3. Webhook an n8n (`[n8n-Webhook-URL]`), signiert mit HMAC, mit Retry und Warteschlange bei Ausfall.
4. Eingangsbestätigung an den Interessenten, textlich sachlich, ohne verbindliche Zusagen.

### 6.5 Admin-Bereich
- Login mit starkem Passwort plus TOTP, Sitzungen mit Timeout, Brute-Force-Schutz.
- Liste mit Filter (Status, Verwaltungsart, Region, Quelle, Zeitraum), Detailansicht, Statuswechsel, Notizen, CSV-Export.
- Dashboard: Leads je Woche, je Quelle, je Verwaltungsart, Konversion je Status.
- Löschkonzept: automatische Löschung oder Anonymisierung verlorener Leads nach `[Aufbewahrungsfrist festlegen]`.

### 6.6 Spam- und Missbrauchsschutz
Honeypot, Zeitfalle, Rate Limiting je IP, serverseitige Plausibilitätsprüfung. Kein Google reCAPTCHA. Falls nötig selbst gehostete Proof-of-Work-Lösung (z. B. ALTCHA).

---

## 7. Wissen und FAQ

Neu schreiben, nicht aus der alten Seite übernehmen. Die alte FAQ enthält fachliche Fehler, u. a. die Aussage, eine Hausverwaltung vertrete Mieterinteressen gegenüber dem Vermieter, sowie Zahlungswege, die bei der HVM nicht vorgesehen sind.

Gliederung nach Zielgruppe:
- WEG und Beirat: Verwalterwechsel, Bestellung und Abberufung, Eigentümerversammlung, Wirtschaftsplan und Jahresabrechnung, Erhaltungsrücklage, Beschlussfassung, Umlaufbeschluss
- Vermieter und Investoren: Mietverwaltung, SE-Verwaltung, Betriebskostenabrechnung, Mieterauswahl, Leerstand
- Mieter: Schadensmeldung, Notfall, Erreichbarkeit, Nebenkostenabrechnung, Portal
- Kosten und Vertrag: Was kostet Verwaltung, was ist enthalten, Vertragslaufzeit, keine Aufnahmegebühr (nur wenn weiterhin gültig)

Regeln:
- Fachlich korrekte, allgemeine Darstellung, keine Rechtsberatung. Normen nur nennen, wenn sicher, sonst allgemein formulieren. Jeder Artikel mit Hinweis, dass er keine Einzelfallberatung ersetzt, und mit Stand-Datum.
- Inhalte als Markdown-Dateien in `/content/wissen/` mit Frontmatter (Titel, Zielgruppe, Stand, Autor, Freigabe ja oder nein). Nur freigegebene Artikel werden veröffentlicht.
- Textentwürfe als Entwurf kennzeichnen, Veröffentlichung erst nach Freigabe durch die Geschäftsführung.
- Strukturierte Daten `FAQPage` und `Article`, interne Verlinkung zu passenden Leistungsseiten und zum Angebotsformular.
- Suchfunktion clientseitig über einen vorab erzeugten Index, ohne externen Dienst.

---

## 8. Texte und Tonalität

- Sachlich, modern, seriös, unternehmerisch. Bestimmt, nie überheblich.
- Keine Gedankenstriche in deutschen Texten, weder — noch –. Stattdessen Komma, Punkt oder Umformulierung. Im Build per Linter prüfen und bei Treffern abbrechen.
- Keine Superlative ohne Beleg („erstklassig“, „beste“), keine Floskeln, keine KI-typischen Wendungen.
- Kundenstimmen nur mit belegbarer Herkunft. Bevorzugt echte Google-Bewertungen, eingebunden ohne Tracking (serverseitig abgerufen oder manuell gepflegt mit Quellenangabe und Datum). Keine Stimmen von Angehörigen oder Mitarbeitern.
- Kennzahlen immer mit Stichtag.

---

## 9. Recht und Datenschutz

- Impressum nach § 5 DDG (Rechtsgrundlage vor Livegang verifizieren). Pflichtangaben aus Abschnitt 2. Keine Steuernummer. Hinweis zur EU-OS-Plattform und Aussage zur Verbraucherschlichtung vor Livegang prüfen lassen `[Freigabe Impressum]`.
- Datenschutzerklärung neu, passend zu tatsächlich eingesetzten Diensten. Entwurf durch Claude Code nur als Gerüst mit Platzhaltern, finale Fassung durch Datenschutzbeauftragten oder Anwalt `[Freigabe Datenschutzerklärung]`.
- Consent: Ziel ist eine Seite ohne einwilligungspflichtige Dienste. Keine Google Fonts extern, keine eingebetteten Google Maps ohne Zwei-Klick-Lösung, keine Tracking-Pixel. Reichweitenmessung, falls gewünscht, über selbst gehostetes, cookieloses Werkzeug `[Analyse-Werkzeug festlegen]`.
- Datensparsamkeit im Formular: nur Felder, die für das Angebot erforderlich sind, Pflichtfelder minimal.
- Barrierefreiheit nach WCAG 2.2 AA, Barrierefreiheitserklärung.

---

## 10. Sicherheit (Lehre aus der alten Seite)

Die alte Seite hat personenbezogene Leaddaten öffentlich ausgegeben. Deshalb verbindlich:
- Keine Debug-Ausgabe in Produktion. `APP_ENV=production` erzwingt `display_errors=Off`, Fehler nur in Logs ohne personenbezogene Daten.
- Build-Check, der jede gerenderte Seite im Staging abruft und auf E-Mail-Adressen und Telefonnummern prüft, die nicht auf einer Whitelist stehen. Treffer brechen das Deployment ab.
- Prepared Statements ausschließlich, Auto-Escaping in Templates, CSRF-Token in allen Formularen.
- Security Header: Content-Security-Policy ohne `unsafe-inline`, HSTS, X-Content-Type-Options, Referrer-Policy, Permissions-Policy.
- Admin-Bereich zusätzlich per IP-Beschränkung oder VPN absicherbar.
- Uploads außerhalb des Webroots, Dateityp-Prüfung, Größenlimit.
- Tägliches Datenbank-Backup mit Verschlüsselung, Wiederherstellung dokumentiert und getestet.

---

## 11. SEO und Performance

- Core Web Vitals: LCP unter 2,0 s, CLS unter 0,05, INP unter 200 ms auf mobil. Lighthouse in allen Kategorien mindestens 95.
- Seitengewicht Startseite unter 500 KB ohne Hero-Bild.
- Saubere URL-Struktur, sprechende Slugs, Canonicals, XML-Sitemap, robots.txt.
- Strukturierte Daten: `Organization`, `LocalBusiness` für den Hauptsitz, `Service` je Leistung, `FAQPage`, `BreadcrumbList`.
- Open-Graph-Bilder je Seite automatisch aus Titel und Kennlinie generieren.
- 301-Redirects aller alten URLs, 404-Seite mit Suche und Kern-CTAs.
- NAP-Daten (Name, Anschrift, Telefon) überall identisch mit dem Impressum.

---

## 12. Projektstruktur (Vorschlag)

```
/public            index.php, assets (css, js, img, fonts)
/src               Controller, Services (LeadService, MailService, WebhookService), Repositories
/templates         Layouts, Partials, Seiten
/content/wissen    Markdown-Artikel
/config            app.php, kennzahlen.php, standorte.php, redirects.php
/migrations        versionierte SQL-Dateien
/bin               import-legacy-leads.php, build-search-index.php, check-pii.php
/tests             Unit- und End-to-End-Tests (Formularstrecke, Redirects, Header)
/docs              Bestandsaufnahme, Bildinventar, offene Punkte, Redirects, Entscheidungen
docker-compose.yml, .env.example, README.md
```

---

## 13. Phasen

**Phase 0: Voraussetzung außerhalb des Neubaus**
Das Datenleck der alten Seite ist geschlossen. Claude Code arbeitet nicht auf der alten Seite, fragt aber zu Beginn ab, ob dies erledigt ist, und dokumentiert die Antwort.

**Phase 1: Inventar**
- `wget --mirror --page-requisites --adjust-extension --convert-links --no-parent https://muellerhv.de` in `/legacy` ausführen. Bei Serverfehlern langsam und mit Pausen wiederholen (`--wait=2 --random-wait --tries=3`).
- Aus dem Mirror erzeugen: vollständige URL-Liste, Seitentexte je URL, Bildinventar (Datei, Größe, Verwendung, Alt-Text, Lizenzstatus offen), Formularfelder, eingebundene Drittdienste.
- Gespiegelte Seiten auf personenbezogene Daten prüfen und diese aus dem Mirror entfernen, bevor irgendetwas weiterverarbeitet oder committet wird. `/legacy` steht in `.gitignore`.
- Ergebnis: Ergänzung der Bestandsaufnahme, Redirect-Map, Bildinventar.

**Phase 2: Designsystem**
Tokens, Typografie, Kennlinie, Orangekeil, Hausumriss, Buttons, Formularelemente, Karten, Navigation. Ausgabe als statische Styleguide-Seite unter `/styleguide` (nur Staging). Freigabe durch Geschäftsführung einholen.

**Phase 3: Seitentemplates**
Start, Leistungsseite, Verwalterwechsel, Wissen, Kontakt als klickbare Prototypen mit Platzhaltertexten. Freigabe einholen.

**Phase 4: Leadstrecke und Admin**
Datenbank, Formular, Verarbeitung, Admin, Import Altbestand, Tests.

**Phase 5: Inhalte**
Texte je Seite als Entwurf, Wissensartikel als Entwurf, Bildauswahl. Alles mit Freigabestatus.

**Phase 6: Recht, SEO, Performance, Sicherheit**
Pflichtseiten, strukturierte Daten, Redirects, Lighthouse, axe, PII-Check, Security-Header-Test.

**Phase 7: Livegang**
Checkliste abarbeiten, DNS-Umstellung erst nach Freigabe, alte Seite als statisches Archiv offline sichern, Monitoring der 404-Fehler in den ersten vier Wochen.

---

## 14. Abnahmekriterien

- Alle Seiten aus Abschnitt 5 vorhanden, alle alten URLs leiten korrekt um.
- Lead wird gespeichert, E-Mail und Webhook kommen an, Status im Admin änderbar, Import der Altleads protokolliert.
- Keine personenbezogenen Daten in öffentlichem HTML, Logs oder Repository.
- WCAG 2.2 AA ohne kritische Befunde, Lighthouse mindestens 95.
- Keine Gedankenstriche in deutschen Texten, keine unbelegten Aussagen, keine erfundenen Daten, alle Platzhalter in `/docs/offene-punkte.md` aufgelöst oder bewusst freigegeben.
- Freigabe der Geschäftsführung für Design, Texte, Impressum, Datenschutz und Livegang liegt dokumentiert vor.

---

## 15. Offene Punkte zum Start (vom Auftraggeber zu liefern)

1. Bestätigung, dass das Datenleck der alten Seite geschlossen ist
2. Zentrale Telefonnummer, Notfallnummer, Lead-Empfängeradresse
3. USt-IdNr., falls vorhanden
4. Export der alten Lead-Tabelle inklusive Preisstufen-Tabellen
5. Logo als Vektordatei
6. Datenquelle der Immobilienangebote und Zugang zur Schnittstelle
7. Staging-Subdomain und n8n-Webhook
8. Liste echter Standorte mit eigener Präsenz und Partnerregionen
9. Freigegebene Referenzobjekte und Fotos
10. Aufbewahrungsfrist für Leads
