# Technisches SEO, GEO und Performance

Stand: 23.09.2026. Umfang: alle öffentlichen Seiten aus `config/seiten.php` (23 Seiten inkl. `/fakten/`) und alle 53 Wissensartikel.
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
| Organization, RealEstateAgent (Unterart von LocalBusiness) | `/#organisation` | Name, legalName, alternateName HVM, Anschrift, Telefon +49 2431 9550300, E-Mail, ContactPoint, foundingDate 2020-03-04, Handelsregister als identifier, memberOf VZIV und IVD, knowsAbout (Leistungen), areaServed nur bestätigte Orte (derzeit Monheim am Rhein), Logo. `sameAs` nur über optionales `same_as` in `config/unternehmen.php`, derzeit weggelassen. Keine vatID, keine Öffnungszeiten, keine Preisspanne (nicht belegt) |
| WebSite | `/#website` | SearchAction auf `/wissen/?q={search_term_string}` (serverseitige Suche im WissenController) |
| WebPage, AboutPage, ContactPage, CollectionPage | `{url}#webseite` | isPartOf WebSite, publisher Organisation, breadcrumb |
| Service (8 Leistungsseiten inkl. Asset Management) | `{url}#leistung` | name, serviceType, provider Organisation, areaServed bestätigt |
| Article (Wissensartikel) | `{url}#artikel` | headline, description, datePublished und dateModified aus `stand`, author und publisher Organisation, image, mainEntityOfPage |
| FAQPage | | nur mit freigegebenen Fragen: `/wissen/` aus `content/faq/*.yaml` (`freigabe`), Artikel-FAQ nur bei `freigabe: ja` |
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

Dynamisch aus der Konfiguration (`SeoController::llms`), Markdown nach llmstxt.org: H1, Kurzbeschreibung als Zitat, Kernfakten mit Stand, Abschnitte Leistungen, Unternehmen und Kontakt, Wissen, Optional (Rechtliches). Wissensartikel nur mit `freigabe: ja`, in keiner Umgebung Entwürfe; ohne freigegebene Artikel steht dort der Link auf `/wissen/`. `/llms-full.txt` wird nicht angeboten.

### 4.3 robots.txt und KI-Crawler (Entscheidung)

In Produktion werden GPTBot, OAI-SearchBot, PerplexityBot, ClaudeBot und Google-Extended ausdrücklich zugelassen (`Allow: /`, `Disallow: /admin/`). Begründung: Die Inhalte sind öffentliche Unternehmens- und Fachinformationen; Nennung und Verlinkung in Antwortmaschinen ist erwünscht. Außerhalb der Produktion bleibt `Disallow: /`.

Die Geschäftsführung kann das jederzeit abschalten: `AI_CRAWLERS=false` in der `.env` (Schalter `ai_crawlers` in `config/app.php`). Dann erhalten diese Crawler `Disallow: /`, Suchmaschinen bleiben unberührt. Hinweis: robots.txt ist eine Bitte, keine technische Sperre.

### 4.4 Wissensartikel

- Optionales Frontmatter-Feld `kurzfassung` (Text oder Liste, höchstens 5 Punkte), angezeigt als Box „Das Wichtigste in Kürze“ vor dem Inhaltsverzeichnis (`templates/partials/wissen/kurzfassung.html.twig`).
- Kurzfassungen aus dem bestehenden Artikeltext abgeleitet für: `hausgeld`, `verwalterwechsel-weg`, `kosten-hausverwaltung`, `sondereigentumsverwaltung`, `asset-management-immobilien`. `freigabe` bleibt `nein`; die Kurzfassungen sind mit dem Artikel freizugeben.
- Sichtbarer Stand (`<time>`) und Autor, Article-Schema wie in Abschnitt 3.

## 5. Sitemap

Alle Seiten mit `sitemap => true`, deren Pfad in der Umgebung registriert ist (inkl. `/fakten/`, `/asset-management/`, `/wissen/`, `/kontakt/`, `/angebot/`), dazu freigegebene Wissensartikel. `lastmod`: Freigabedatum aus `config/freigaben.php`, bei `/wissen/` zusätzlich das neueste Stand-Datum freigegebener Artikel, bei Artikeln das Stand-Datum. Das Dateidatum der Vorlage wird nicht mehr verwendet, weil es nach Checkout und Container-Build nur das Build-Datum zeigt. Ohne Freigabedatum entfällt `lastmod` (korrekter als ein falsches Datum). Keine Bilder-Sitemap.

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
- IVD-Langform, Öffnungszeiten und Notfallnummer fehlen in den Stammdaten; werden erst nach Bestätigung in Schema und Faktenseite übernommen.
- Twig-Cache (`storage/cache/twig`) wird in Produktion nicht automatisch erneuert; bei Deployments ohne neuen Container leeren.
- Rich-Results-Test gegen Staging vor Livegang (Abschnitt 3).
