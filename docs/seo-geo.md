# Technisches SEO, GEO und Performance

Stand: 24.09.2026. Umfang: alle öffentlichen Seiten aus `config/seiten.php` (23 Seiten inkl. `/fakten/`), alle 53 Wissensartikel und die 42 Stadtseiten (Abschnitt 8).
GEO steht für Generative Engine Optimization: Sichtbarkeit in KI-Suchen und Antwortmaschinen (Google AI Overviews, ChatGPT, Perplexity, Claude).

## 1. Ergebnis in Kürze

- SEO-Audit: alle Befunde behoben. Titel höchstens 60, Beschreibungen höchstens 160 Zeichen, eindeutig; genau eine `h1` ohne Attribute; keine Sprünge in der Überschriftenhierarchie; canonical, hreflang `de-DE` und `x-default`, Open Graph und Twitter-Card auf jeder Seite; BreadcrumbList auf jeder Unterseite.
- Strukturierte Daten: 76 Seiten, 303 JSON-LD-Blöcke, 0 Fehler (`php bin/check-structured-data.php`).
- GEO: Faktenseite `/fakten/`, dynamische `/llms.txt`, KI-Crawler in `robots.txt` ausdrücklich zugelassen (abschaltbar), Kurzfassung „Das Wichtigste in Kürze“ in Wissensartikeln.
- Performance (produktionsnahe Auslieferung mit gzip): alle Kategorien 100 auf allen gemessenen Seiten (Ausnahme SEO des Artikel-Entwurfs, siehe Fußnote), LCP mobil 1,27 bis 1,33 s, CLS höchstens 0,005.

## 2. SEO-Audit und Korrekturen

| Befund vorher | Korrektur |
|---|---|
| 5 Seitentitel und 41 Artikeltitel über 60 Zeichen | `config/seiten.php` gekürzt (Start, SE-Verwaltung, Asset Management, Wissen, Service). Artikeltitel automatisch: `PageMeta::seitentitel()` probiert „Titel | Hausverwaltung Müller GmbH“, dann „Titel | Hausverwaltung Müller“, dann Titel allein, dann Teil vor dem Doppelpunkt |
| Doppelter Titel `/asset-management/` und Artikel `asset-management-immobilien` | Seitentitel der Leistungsseite gekürzt, beide eindeutig |
| 2 Artikelbeschreibungen über 160 Zeichen | Frontmatter gekürzt (`index-staffelmiete`, `mietverwaltung-oder-se-verwaltung`), keine neuen Inhalte |
| kein hreflang | `<link rel="alternate" hreflang="de-DE">` und `x-default` auf die canonical-URL |
| Artikel ohne og:image und Twitter-Card | Artikel nutzen das OG-Bild der Wissensseite, `og:type article`, `article:modified_time`; Twitter-Card auf jeder Seite (`summary` ohne Bild) |
| Danke- und Fehlerseiten ohne robots-Angabe in Produktion | `noindex, follow` für alle Seiten ohne `sitemap => true`; indexierbare Seiten `index, follow, max-snippet:-1, max-image-preview:large` |
| Artikel ohne sichtbares Autorendatum im Markup | `<time datetime>` für den Stand, „Autor: …“ |

Unverändert und geprüft: Alt-Texte (nur Logos, jeweils in Links mit `aria-label`, daher `alt=""` korrekt), interne Verlinkung (jede Seite mindestens 3 interne Links im Inhalt, Ausnahme Barrierefreiheitserklärung mit 2, als Rechtsseite vertretbar), Breadcrumbs sichtbar und als BreadcrumbList.

## 3. Strukturierte Daten

Aufbau je indexierbarer Seite (`src/View/SchemaBuilder.php`), Entitäten über feste `@id` verknüpft:

| Entität | @id | Inhalt |
|---|---|---|
| Organization, RealEstateAgent (Unterart von LocalBusiness) | `/#organisation` | Name, legalName, alternateName HVM, Anschrift, Telefon +49 2431 9550300, E-Mail, ContactPoint, foundingDate 2020-03-04, Handelsregister als identifier, memberOf VZIV und IVD, knowsAbout (Leistungen), areaServed Hauptsitz Monheim am Rhein (City) und Deutschland (Country, 42 PLZ-Bereiche laut `config/staedte.php`), openingHoursSpecification Montag bis Freitag 08:00 bis 16:00 (`oeffnungszeiten` in `config/unternehmen.php`, bestätigt 24.09.2026), Logo. `sameAs` nur über optionales `same_as` in `config/unternehmen.php`, derzeit weggelassen. Keine vatID, keine Preisspanne (nicht belegt) |
| WebSite | `/#website` | SearchAction auf `/wissen/?q={search_term_string}` (serverseitige Suche im WissenController) |
| WebPage, AboutPage, ContactPage, CollectionPage | `{url}#webseite` | isPartOf WebSite, publisher Organisation, breadcrumb |
| Service (8 Leistungsseiten inkl. Asset Management) | `{url}#leistung` | name, serviceType, provider Organisation, areaServed wie Organisation |
| Service (Stadtseiten `/hausverwaltung-{slug}/`) | `{url}#leistung` | name „Hausverwaltung <Stadt>“, serviceType WEG-, Miet- und SE-Verwaltung, provider Organisation, areaServed City (mit Bundesland) und DefinedRegion mit PostalCodeRangeSpecification (PLZ von bis). Kein LocalBusiness und keine PostalAddress je Stadt |
| Article (Wissensartikel) | `{url}#artikel` | headline, description, datePublished und dateModified aus `stand`, author und publisher Organisation, image, mainEntityOfPage |
| FAQPage | | nur mit freigegebenen Fragen: `/wissen/` aus `content/faq/*.yaml` (`freigabe`), Artikel- und Stadtseiten-FAQ nur bei `freigabe: ja` |
| BreadcrumbList | `{url}#brotkrumen` | jede Unterseite |

Nicht verwendet: DefinedTerm, Dataset (keine belastbare Grundlage).

Validierung `php bin/check-structured-data.php` (ohne Server über den Kernel): JSON parsebar, `@context`, Pflichtfelder je Typ, alle `@id`-Verweise auf der Seite auflösbar, BreadcrumbList vollständig, eine h1, Titel und Beschreibungslänge, canonical, hreflang, og:image, Twitter-Card.

```
staging (mit Entwürfen): 76 Seiten, 303 JSON-LD-Blöcke, Keine Fehler.
  AboutPage 2, Article 53, BreadcrumbList 75, CollectionPage 2, ContactPage 1,
  Organization 76, RealEstateAgent 76, Service 8, WebPage 10, WebSite 76
production: 23 Seiten, 91 JSON-LD-Blöcke, Keine Fehler (Artikel noch nicht freigegeben)
```

Vor Livegang ergänzend mit dem Rich-Results-Test von Google und dem Schema Markup Validator gegen die Staging-URL prüfen.

## 4. GEO

### 4.1 Faktenseite /fakten/

„Die HVM in Zahlen und Fakten“: Tabelle (Firma, Sitz, Register, Geschäftsführer, Gründung, Bestand, Mitgliedschaften, Leistungen), Definitionsliste der Leistungsbegriffe (je ein Satz), bestätigte Merkmale, Kontakt und Zitierhinweis. Jede Angabe mit Stand-Datum (Stammdaten 23.09.2026, Bestand 01.07.2026). Quellen ausschließlich `config/unternehmen.php`, `config/kennzahlen.php` und `config/fakten.php` (Angaben der Geschäftsführung laut `docs/auftraggeber-angaben.md`). Schema: Organisation, AboutPage, BreadcrumbList. Verlinkt im Footer (Gruppe Unternehmen) und auf „Über uns“. Controller `FaktenController`, Route `config/routes/seo.php`, Freigabe-Eintrag in `config/freigaben.php` (Entwurf).
Screenshots: `docs/screenshots/fakten-desktop.png`, `docs/screenshots/fakten-mobil.png`.

### 4.2 /llms.txt

Dynamisch aus der Konfiguration (`SeoController::llms`), Markdown nach llmstxt.org: H1, Kurzbeschreibung als Zitat, Kernfakten mit Stand, Abschnitte Leistungen, Unternehmen und Kontakt, Wissen, Optional (Rechtliches). Wissensartikel nur mit `freigabe: ja`, in keiner Umgebung Entwürfe; ohne freigegebene Artikel steht dort der Link auf `/wissen/`. Abschnitt „Betreuungsgebiete“: Link auf `/betreuungsgebiete/` und nur die indexierbaren Stadtseiten (freigegeben und mit lokalem Bezug), dazu in den Kernfakten die Zeile „Betreuungsgebiete: bundesweit in 42 Postleitzahlbereichen“. `/llms-full.txt` wird nicht angeboten.

### 4.3 robots.txt und KI-Crawler (Entscheidung)

In Produktion werden GPTBot, OAI-SearchBot, PerplexityBot, ClaudeBot und Google-Extended ausdrücklich zugelassen (`Allow: /`, `Disallow: /admin/`). Begründung: Die Inhalte sind öffentliche Unternehmens- und Fachinformationen; Nennung und Verlinkung in Antwortmaschinen ist erwünscht. Außerhalb der Produktion bleibt `Disallow: /`.

Die Geschäftsführung kann das jederzeit abschalten: `AI_CRAWLERS=false` in der `.env` (Schalter `ai_crawlers` in `config/app.php`). Dann erhalten diese Crawler `Disallow: /`, Suchmaschinen bleiben unberührt. Hinweis: robots.txt ist eine Bitte, keine technische Sperre.

### 4.4 Wissensartikel

- Optionales Frontmatter-Feld `kurzfassung` (Text oder Liste, höchstens 5 Punkte), angezeigt als Box „Das Wichtigste in Kürze“ vor dem Inhaltsverzeichnis (`templates/partials/wissen/kurzfassung.html.twig`).
- Kurzfassungen aus dem bestehenden Artikeltext abgeleitet für: `hausgeld`, `verwalterwechsel-weg`, `kosten-hausverwaltung`, `sondereigentumsverwaltung`, `asset-management-immobilien`. `freigabe` bleibt `nein`; die Kurzfassungen sind mit dem Artikel freizugeben.
- Sichtbarer Stand (`<time>`) und Autor, Article-Schema wie in Abschnitt 3.

## 5. Sitemap

Alle Seiten mit `sitemap => true`, deren Pfad in der Umgebung registriert ist (inkl. `/fakten/`, `/asset-management/`, `/wissen/`, `/kontakt/`, `/angebot/`, `/betreuungsgebiete/`), dazu freigegebene Wissensartikel und indexierbare Stadtseiten (`StadtSitemapProvider`: `freigabe: ja` und `indexierbar` in `config/staedte.php`, `lastmod` aus `stand`). `lastmod`: Freigabedatum aus `config/freigaben.php`, bei `/wissen/` zusätzlich das neueste Stand-Datum freigegebener Artikel, bei Artikeln das Stand-Datum. Das Dateidatum der Vorlage wird nicht mehr verwendet, weil es nach Checkout und Container-Build nur das Build-Datum zeigt. Ohne Freigabedatum entfällt `lastmod` (korrekter als ein falsches Datum). Keine Bilder-Sitemap.

## 6. Performance

Messung 23.09.2026, Lighthouse 12, Chromium 1194 headless, Standard-Throttling (mobil: simuliertes langsames 4G und 4-fache CPU-Drosselung; Desktop-Preset). Server `php -S 127.0.0.1:8773` mit `APP_ENV=production` und gültigem `APP_KEY`. Davor ein lokaler Proxy, der die Nginx-Auslieferung nachbildet (gzip Stufe 5, Cache-Control wie `docker/nginx/default.conf`), weil `php -S` nicht komprimiert.

| Seite (mobil) | Perf. | Access. | Best Pr. | SEO | LCP | CLS | TBT | Gewicht | Requests |
|---|---|---|---|---|---|---|---|---|---|
| / | 100 | 100 | 100 | 100 | 1,28 s | 0,000 | 8 ms | 39 KB | 15 |
| /weg-verwaltung/ | 100 | 100 | 100 | 100 | 1,33 s | 0,000 | 0 ms | 39 KB | 15 |
| /verwalterwechsel/ | 100 | 100 | 100 | 100 | 1,32 s | 0,004 | 0 ms | 39 KB | 15 |
| /asset-management/ | 100 | 100 | 100 | 100 | 1,31 s | 0,000 | 0 ms | 39 KB | 15 |
| /wissen/ | 100 | 100 | 100 | 100 | 1,30 s | 0,000 | 0 ms | 37 KB | 15 |
| /wissen/hausgeld/ (1) | 100 | 100 | 100 | 69 | 1,32 s | 0,000 | 1 ms | 40 KB | 15 |
| /angebot/ | 100 | 100 | 100 | 100 | 1,31 s | 0,005 | 0 ms | 45 KB | 18 |
| /fakten/ | 100 | 100 | 100 | 100 | 1,27 s | 0,000 | 0 ms | 37 KB | 15 |

| Seite (Desktop) | Perf. | Access. | Best Pr. | SEO | LCP | CLS | TBT | Gewicht | Requests |
|---|---|---|---|---|---|---|---|---|---|
| / | 100 | 100 | 100 | 100 | 0,37 s | 0,000 | 0 ms | 39 KB | 15 |
| /weg-verwaltung/ | 100 | 100 | 100 | 100 | 0,37 s | 0,000 | 0 ms | 39 KB | 15 |
| /verwalterwechsel/ | 100 | 100 | 100 | 100 | 0,36 s | 0,000 | 0 ms | 39 KB | 15 |
| /asset-management/ | 100 | 100 | 100 | 100 | 0,35 s | 0,000 | 0 ms | 39 KB | 15 |
| /wissen/ | 100 | 100 | 100 | 100 | 0,36 s | 0,000 | 0 ms | 37 KB | 15 |
| /wissen/hausgeld/ (1) | 100 | 100 | 100 | 69 | 0,36 s | 0,000 | 0 ms | 40 KB | 15 |
| /angebot/ | 100 | 100 | 100 | 100 | 0,38 s | 0,001 | 0 ms | 56 KB | 19 |
| /fakten/ | 100 | 100 | 100 | 100 | 0,37 s | 0,000 | 0 ms | 37 KB | 15 |

(1) Wissensartikel sind noch nicht freigegeben und in Produktion nicht erreichbar. Gemessen auf einer Staging-Instanz (`SHOW_DRAFTS=true`); SEO 69 allein wegen des dort gewollten `noindex` (Audit „is-crawlable“). Alle übrigen SEO-Audits bestanden; nach Freigabe in Produktion ist 100 zu erwarten.

Direkt gegen `php -S` ohne Kompression: Performance 98 bis 100, LCP mobil 1,95 bis 2,10 s, Seitengewicht 132 bis 191 KB. Der Unterschied erklärt sich vollständig durch das unkomprimierte CSS-Bundle (78 KB, gzip rund 15 KB).

Optimierungen dieser Runde:

- `/angebot/`: CLS mobil von 0,062 auf 0,005. Ursache: Der Stepper war mit `hidden` ausgezeichnet und wurde erst durch `angebot.js` eingeblendet, das Formular rutschte um 126 px. Jetzt ohne `hidden` im Markup, ohne JavaScript per `@media (scripting: none)` ausgeblendet (`templates/pages/angebot.html.twig`, `resources/css/45-angebot.css`). Der No-JS-Test in `tests/e2e/angebot.spec.js` bleibt grün.
- Geprüft und bewusst nicht geändert: Das CSS steht als erste Ressource im `head`, ein zusätzliches `preload` bringt nichts. Es gibt kein LCP-Bild (LCP ist die Hero-Überschrift), daher kein `fetchpriority`. Skripte sind ES-Module (`type="module"`, wirken wie `defer`), nicht render-blockierend, TBT 0 bis 8 ms.

Empfehlungen für Nginx (`docker/nginx/default.conf`, nur gelesen):

- `gzip_types` um `text/markdown` ergänzen (für `/llms.txt`).
- Optional `gzip_static on;` mit vorkomprimierten `.gz`-Dateien aus `bin/build-assets.php` bzw. Brotli, falls das Modul im Image verfügbar ist (spart CPU je Anfrage, Brotli verkleinert Text-Ressourcen in der Regel weiter).
- Cache-Control ist passend: `/assets/build/` ein Jahr `immutable`, übrige Assets 7 Tage, OG-Bilder 1 Tag, HTML ohne langes Caching.

## 7. Offene Punkte

- Freigabedaten in `config/freigaben.php` setzen, damit die Sitemap `lastmod` für statische Seiten liefert.
- `sameAs`: eigene Profile (z. B. Verbandsverzeichnis VZIV, Google Unternehmensprofil) nur nach Bestätigung in `config/unternehmen.php` als `same_as` ergänzen.
- IVD-Langform fehlt in den Stammdaten. Öffnungszeiten (Montag bis Freitag, 8 bis 16 Uhr) sind seit 24.09.2026 im Organization-Schema; Notfallnummer ist bestätigt, auf der Faktenseite bei Bedarf ergänzen.
- Stadtseiten: vor Livegang freigeben (mindestens die Ziele der Altseiten-Weiterleitungen, `docs/redirects.md`), alle 42 Städte sind seit 24.09.2026 indexierbar; Stadtseiten mit echten lokalen Inhalten weiter anreichern (Abschnitt 8).
- Twig-Cache (`storage/cache/twig`) wird in Produktion nicht automatisch erneuert; bei Deployments ohne neuen Container leeren.
- Rich-Results-Test gegen Staging vor Livegang (Abschnitt 3).

## 8. Stadtseiten und lokale Präsenz

Umsetzung: 42 Betreuungsgebiete (PLZ-Bereiche) in `config/staedte.php`, je Stadt eine Seite `/hausverwaltung-<slug>/` mit eigenständigem Text aus `content/staedte/<slug>.md` (`Hvm\Controller\StadtController`, `templates/pages/stadt.html.twig`). Zentrale, indexierbare Übersicht ist `/betreuungsgebiete/` mit allen 42 Städten nach Bundesland, PLZ-Bereich, Karte und normalen (follow) Links. Im Footer steht nur der Link auf die Übersicht, kein Linkblock mit allen Städten. Jede Stadtseite verlinkt nur die drei nächstgelegenen Stadtseiten (Luftlinie der Stadtzentren) und die Übersicht.

Regeln (Entscheidungen der Geschäftsführung vom 24.09.2026, `docs/auftraggeber-angaben.md`): Büro ausschließlich in Monheim am Rhein; die übrigen 41 Städte sind Betreuungsgebiete mit Mitarbeitern vor Ort, ohne Büros und ohne Anschriften.

| Regel | Umsetzung |
|---|---|
| Indexierung | `indexierbar` in `config/staedte.php` ist für alle 42 Städte true (`$standardIndexierbar = true`). Freigegebene Stadtseiten stehen mit `index, follow` in `sitemap.xml` und `llms.txt`, Canonical auf sich selbst. Tests: `tests/Unit/Content/StadtRepositoryTest.php`, `tests/Unit/Controller/StadtControllerTest.php` |
| Schalter zum Zurücksetzen | In `config/staedte.php` `$standardIndexierbar = false` setzen. Dann sind nur der Hauptsitz und die Städte mit bereits verwalteten Objekten (`bestand_orte`: Berlin, Köln, Erkelenz, Essen, Aachen, Ulm) indexierbar, weil sie `indexierbar: true` ausdrücklich gesetzt haben. Übrige Stadtseiten: `noindex, follow`, nicht in `sitemap.xml` und `llms.txt`, Canonical auf sich selbst. Einzelne Städte lassen sich mit `indexierbar: false` im Eintrag ausnehmen. Der Test `testSchalterZumZuruecksetzenLaesstNurHauptsitzUndStaedteMitBestandIndexierbar` sichert das Verhalten ab |
| Freigabe wie Wissensartikel | `freigabe: ja` im Frontmatter; Entwürfe in Produktion 404, auf Staging mit `SHOW_DRAFTS=true` sichtbar mit Entwurfsband, nie in Sitemap oder llms.txt |
| Kein vorgetäuschter Standort | Keine Anschrift, kein Telefon und kein Ansprechpartner je Stadt, kein LocalBusiness und keine PostalAddress außer dem Hauptsitz. Der Kontaktblock jeder Stadtseite nennt „Betreuung durch Mitarbeiter der Hausverwaltung Müller GmbH vor Ort in der Region. Büro und Verwaltungssitz: Rheinpromenade 13, 40789 Monheim am Rhein. Termine nach Absprache.“, die Seite Monheim am Rhein „Büro am Hauptsitz“. Die Stadttexte beschreiben Mitarbeiter vor Ort in der Region und die zentrale Verwaltung (Buchhaltung, Abrechnung, Portal) in Monheim am Rhein, ohne Büro, Anschrift, Anzahl oder Namen von Mitarbeitern |
| Strukturierte Daten | Service je Stadt mit areaServed (City und PLZ-Bereich), BreadcrumbList Start > Betreuungsgebiete > Stadt, FAQPage nur bei Freigabe. Kein LocalBusiness je Stadt; einziger RealEstateAgent ist die Organisation mit dem Hauptsitz |
| Eigenständige Texte | Ähnlichkeitsprüfung der Stadttexte (Jaccard-Koeffizient der Wort-3-Gramme über alle Paare) nach jeder Änderung; Grenzwert 0,15, Stand 24.09.2026 höchstes Paar rund 0,07 |

Begründung (Einschätzung, keine Rechtsberatung):

- Google-Richtlinien zu Spam, Abschnitt Doorway-Seiten: Viele fast gleichartige Seiten, die nur für einzelne Orte ranken sollen und Nutzer auf dieselbe Leistung weiterleiten, gelten als Doorway-Seiten und können die Sichtbarkeit der gesamten Website mindern. Das Risiko ist nach der Bestätigung vom 24.09.2026 reduziert: In allen Betreuungsgebieten betreuen Mitarbeiter die Objekte vor Ort, und jede Stadtseite hat einen eigenständigen Text mit eigenem Schwerpunkt (Ähnlichkeit aller Paare deutlich unter dem Grenzwert). Deshalb werden alle 42 Stadtseiten indexiert. Ganz ausgeschlossen ist eine Einstufung als Doorway-Seiten nicht; bei sichtbaren Problemen (etwa Hinweisen in der Search Console oder deutlichem Sichtbarkeitsverlust) den Schalter zurücksetzen.
- Weiter anreichern: Stadtseiten gewinnen an Eigenständigkeit durch echte lokale Inhalte, etwa freigegebene Referenzen aus dem Betreuungsgebiet, Ansprechpartner (nur mit Einwilligung und Freigabe) oder regionale Hinweise. Solche Inhalte nur mit Freigabe der Geschäftsführung aufnehmen.
- Google-Unternehmensprofile: Ein Profil setzt nach den Richtlinien von Google einen Standort mit persönlichem Kundenkontakt während der angegebenen Zeiten voraus; virtuelle Büros oder reine Postanschriften sind nicht zulässig. Außerhalb von Monheim am Rhein gibt es keine Büros. Daher weiterhin keine Unternehmensprofile, keine LocalBusiness-Einträge und keine Anschriften an Orten ohne Büro.
- Wettbewerbsrecht: Die Angabe von Standorten, die tatsächlich nicht bestehen, kann als irreführende geschäftliche Handlung nach dem UWG (§ 5 UWG) angreifbar sein, etwa durch Mitbewerber oder Verbände. Die Stadtseiten sprechen deshalb von Mitarbeitern vor Ort und nennen das Büro in Monheim am Rhein ausdrücklich, ohne ein Büro in der jeweiligen Stadt nahezulegen. Formulierungen vor Livegang anwaltlich gegenprüfen lassen (`docs/offene-punkte.md` B26).
