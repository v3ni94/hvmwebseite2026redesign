# Designsystem muellerhv.de

Stand: 23.09.2026. Status: Entwurf, Freigabe durch die Geschäftsführung ausstehend (MP Phase 2).
Grundlage: `docs/architektur.md` Abschnitt 4, 6 und 7, Masterprompt (MP) Abschnitt 3, 5 und 8, CI-Skill hvm-ci, `docs/logo/geometrie.md`.
Ansicht aller Bausteine: `/styleguide/` (nur außerhalb der Produktion).

## 1. Grundsätze

- Klar, ruhig, architektonisch: viel Weißraum, große Typografie, 12-Spalten-Raster, wenige starke Akzente (Orange nur als Fläche).
- Keine Bilder in dieser Phase. Die Gestaltung trägt sich über Typografie, Raster und drei Signaturelemente aus dem Logo: Kennlinie, Orangekeil, Hausumriss. Dazu die Graustufen H, V, M.
- Leitmotiv aus der Wortmarke (HAUSVERWALTUNG leicht, MÜLLER fett): Überschriften leicht oder regulär, genau ein Schlüsselwort fett.
- CSP ohne `unsafe-inline`: keine `style`-Attribute, keine `on*`-Handler, Skripte nur als Modul mit Nonce, dynamische Werte ausschließlich über `element.style.setProperty()`.
- Progressive Enhancement: jede Seite ist ohne JavaScript vollständig nutzbar.

## 2. Dateien

| Datei | Inhalt |
|---|---|
| `resources/css/00-tokens.css` | Layer-Reihenfolge, alle Tokens |
| `resources/css/01-reset.css` | Reset |
| `resources/css/02-base.css` | Typografie, Links, Fokus |
| `resources/css/03-layout.css` | Container, Raster, Sektionen, dunkle Sektion |
| `resources/css/10-signatur.css` | Kennlinie, Fortschrittsbalken, Orangekeil, Hausumriss |
| `resources/css/20-komponenten.css` | Display, Eyebrow, Section-Head, Buttons, Textlink, Platzhalter, Entwurfsband |
| `resources/css/21-inhalte.css` | Karten, Bento, Kennzahlen, Zeitleiste, FAQ, CTA-Band, Breadcrumbs, Tabelle, Prosa, Suche, Hinweis, Kundenstimmen |
| `resources/css/30-formulare.css` | Felder, Auswahlkarten, Checkbox, Stepper |
| `resources/css/40-kopf-fuss.css` | Header, Navigation, Footer, mobile Bottom-Bar |
| `resources/css/50-seiten.css` | Hero, Seitenkopf, Startseite, 404, Styleguide |
| `resources/css/80-bewegung.css` | Einblenden, View Transitions, reduzierte Bewegung, Druck |
| `resources/css/90-utilities.css` | `u-visually-hidden`, `u-skip-link` und wenige Helfer |
| `resources/js/app.js` | Einstieg, lädt die Module |
| `resources/js/modules/kopfzeile.js` | kompakter Header, Untermenüs, mobile Navigation |
| `resources/js/modules/einblenden.js` | Fallback für scroll-getriebenes Einblenden |
| `resources/js/modules/zaehler.js` | Zähleranimation der Kennzahlen |
| `resources/js/modules/fortschritt.js` | Fortschrittsbalken, Lesefortschritt, Export `setzeFortschritt()` |
| `templates/layouts/base.html.twig` | Basislayout |
| `templates/partials/*` | header, footer, mobile-bar, draft-banner, breadcrumbs, kennlinie, hausumriss, icon |
| `templates/components/*` | Twig-Makros (Abschnitt 6) |

Cascade Layers: `@layer reset, tokens, base, layout, components, utilities, overrides;` Die Schicht `overrides` bleibt für Seitenausnahmen frei. Die Regel `@view-transition` steht außerhalb der Layer.

## 3. Tokens

### 3.1 Farben (ausschließlich MP 3.2)

| Token | Hex | Einsatz |
|---|---|---|
| `--hvm-orange` | #E6A83C | Primärbutton, Fokusring außen, Keil, Kennlinie, Platzhalter, Entwurfsband. Nie Textfarbe auf Weiß |
| `--hvm-ink` | #1A1A1A | Fließtext, Überschriften, dunkle Sektionen (Hero, Kennzahlen, CTA, Footer, mobile Bar) |
| `--hvm-anthrazit` | #87888A | Bento H, Feldränder, große Zahl der 404-Seite. Als Text auf Weiß nur ab 24 px |
| `--hvm-mittelgrau` | #9C9D9F | Bento V, Trennzeichen der Breadcrumbs (dekorativ) |
| `--hvm-hellgrau` | #D7D8DA | Bento M, Kartenränder, Linien, Labels auf dunklem Grund |
| `--hvm-umriss` | #ECECEC | Hausumriss, Flächensektionen, Hinweisboxen, Tabellenzeilen |
| `--hvm-weiss` | #FFFFFF | Grundfläche |

Rollen (`--farbe-*`, `--fokus-innen`, `--fokus-aussen`) verweisen nur auf diese Werte. Transparenzen entstehen ausschließlich per `color-mix()` aus CI-Farben: `--glas` (92 % Weiß), `--linie-auf-dunkel` (16 % Weiß), Hausumriss auf Dunkel (11 % Umriss), Rasterdarstellung im Styleguide (28 % Orange).

Die im Logo gemessene Überlagerungsfarbe #B0B1B3 (siehe `docs/logo/nachzeichnung-pruefung.md`) kommt nur in der Logodatei vor und ist kein Token.

### 3.2 Abgeleitete Tokens

| Gruppe | Tokens | Werte |
|---|---|---|
| Keil | `--hvm-keil-winkel`, `--hvm-keil-verhaeltnis` | 20.4deg gegen die Vertikale (gemessen am Logo), Breite zu Höhe 0,372 = tan(20,4 Grad) |
| Kennlinie | `--hvm-kennlinie-hoehe`, `--hvm-kennlinie-schraege` | 4 px, Schräge 0,9 × Bandhöhe (CI-Skill) |
| Schrift | `--schrift`, `--fw-leicht` bis `--fw-fett` | `system-ui, "Helvetica Neue", Helvetica, Arial, sans-serif`, 300 bis 700 |
| Schriftgrößen | `--fs-display`, `--fs-h1` bis `--fs-h4`, `--fs-lead`, `--fs-text`, `--fs-klein`, `--fs-label`, `--fs-kennzahl` | siehe 3.3 |
| Zeilen | `--lh-display` 1,05, `--lh-titel` 1,12, `--lh-text` 1,6, `--zeilenlaenge` 70ch | |
| Tracking | `--tracking-display` minus 0,02 em, `--tracking-label` 0,14 em | |
| Abstände | `--abstand-1` bis `--abstand-10` | 4, 8, 12, 16, 24, 32, 48, 64, 96, 128 px |
| Sektionen | `--sektion-y`, `--sektion-y-kompakt` | 64 px mobil, 96 bis 160 px Desktop (fluid) |
| Raster | `--container-max` 1280 px, `--rand` 16 bis 40 px, `--spalten-abstand` 16 bis 32 px | |
| Radien | `--radius-0` 0, `--radius-1` 2 px, `--radius-2` 4 px | bewusst fast rechtwinklig |
| Schatten | `--schatten-1`, `--schatten-2` | aus `--hvm-ink` mit 8 % bzw. 22 % |
| Bewegung | `--ease` cubic-bezier(0.2, 0.7, 0.2, 1), `--dauer-kurz` 200 ms, `--dauer` 400 ms, `--dauer-lang` 500 ms, `--versatz` 12 px | ein Easing-Token |
| Kopfzeile | `--header-h`, `--header-h-kompakt`, `--logo-voll-b`, `--logo-kurz-h` | Desktop 156/76 px, mobil 112/64 px |

### 3.3 Typoskala (fluid zwischen 360 und 1440 px Viewport)

| Stufe | Größe | Gewicht | Zeilenhöhe |
|---|---|---|---|
| Display | 36 bis 96 px (48 px ab 576 px Viewport) | 300, Schlüsselwort 700 | 1,05, Tracking minus 0,02 em |
| H1 | 40 bis 72 px | 300 | 1,05 |
| H2 | 32 bis 56 px | 300 | 1,12 |
| H3 | 22 bis 28 px | 400 | 1,12 |
| H4 | 20 px | 600 | 1,12 |
| Lead | 20 bis 24 px | 300 | 1,45 bis 1,5 |
| Fließtext | 17 bis 18 px | 400 | 1,6, höchstens 70 Zeichen |
| Klein | 15 px | 400 bis 600 | |
| Eyebrow | 13 px, Versalien | 600 | Tracking 0,14 em |
| Kennzahl | 48 bis 88 px | 300 | 0,95, tabellarische Ziffern |

Überschriften nutzen `text-wrap: balance` und `hyphens: auto` (die Seite hat `lang="de"`).

## 4. Signaturelemente

### 4.1 Kennlinie

Segmente Anthrazit 0 bis 40 %, Mittelgrau 40 bis 60 %, Orange 60 bis 67,5 %, Hellgrau 67,5 bis 100 %, diagonale Übergänge mit Schräge 0,9 × Bandhöhe.

Umsetzung als ein `linear-gradient` mit harten Stopps, dessen Winkel senkrecht auf den Schrägen steht: 90 Grad plus atan(0,9) = 131,99 Grad. Damit die Grenzen an der Oberkante exakt bei 40, 60 und 67,5 % der Breite liegen, wird jeder Stopp korrigiert:

```
Stopp(p) = p × 100 % minus p × 0,669 × Bandhöhe      (0,669 = |cos 131,99 Grad|)
```

Rechenweg: Die Verlaufslänge beträgt L = 0,7431 × Breite + 0,6691 × Höhe. Ein Punkt x an der Oberkante liegt bei 0,7431 × x. Für x = p × Breite ergibt sich p × (L minus 0,6691 × Höhe). Der Punkt x minus 0,9 × Höhe an der Unterkante liegt bei 0,7431 × x minus 0,6688 × Höhe plus 0,6691 × Höhe, also auf derselben Stopplinie. Das Ergebnis ist geometrisch identisch mit der Briefbogen-Kennlinie (`hvm_briefkopf.py`, Funktion `kennlinie`). Ein zusätzlicher `clip-path` (MP 3.4.1) ist dadurch nicht nötig; die Schrägen entstehen direkt im Verlauf. Die Schräge ist im Styleguide bei 24 und 48 px deutlich sichtbar.

Einsatz: oberer Seitenrand (4 px, im Header, damit sie auch im kompakten Zustand sichtbar bleibt), Footer-Oberkante, Oberkante des Leistungen-Untermenüs, Verbindungsstrich der Zeitleiste, Fortschrittsbalken.

Fortschrittsbalken `.c-fortschritt`: Die Kennlinie läuft immer über die volle Spurbreite, sichtbar ist der Anteil bis `--fortschritt` (per `clip-path: inset()`). Ohne JavaScript setzt `data-wert` den Wert in 5er-Schritten, mit JavaScript setzt `setzeFortschritt(element, wert)` beliebige Werte und `aria-valuenow`. Lesefortschritt: `<div class="c-fortschritt c-fortschritt--lesen" data-lesefortschritt="#artikel" role="progressbar" ...><span class="c-fortschritt__balken"></span></div>`.

In der vertikalen Zeitleiste (mobil) läuft die Kennlinie senkrecht ohne Schräge, da die Schräge bei 4 px Breite nicht wahrnehmbar wäre.

### 4.2 Orangekeil

Rechtwinkliges Dreieck wie im Logo (obere Kante waagrecht, linke Kante senkrecht, Diagonale im Winkel `--hvm-keil-winkel`). Klasse `.c-keil` mit Breite `--keil-b`, Höhe = Breite / 0,372.

Einsatz: Marker vor Eyebrows, Sektionsübergang (`.c-keil-uebergang`, hängt an der Unterkante des dunklen Hero bei 60 % der Breite, also an der Position des Orange-Segments der Kennlinie), Akzent im CTA-Band und auf der Verwalterwechsel-Karte, Markierung gewählter Auswahlkarten, Stichtag der Kennzahlen. Hover-Wischeffekt auf Karten und Buttons: eine Fläche mit `skewX(-20.4deg)` fährt von links ein.

### 4.3 Hausumriss

Partial `partials/hausumriss.html.twig`. Geometrie aus dem CI-Skill: Breite 115, Wandhöhe 62, Dachhöhe 42 (relative Einheiten), unten offen, runde Enden und Verbindungen. Pfad `M0 104V42L57.5 0 115 42V104`, Strichstärke 3,2 Einheiten (Verhältnis des Briefbogens: 9 pt bei 115 mm). Farbe `--hvm-umriss`, auf dunklem Grund 11 % Deckkraft. Mit `zeichnen: true` zeichnet sich der Umriss einmal über `stroke-dashoffset` nach (`pathLength="1"`, 1,6 s), nicht bei reduzierter Bewegung. Im Hero groß und über den rechten und unteren Rand angeschnitten.

### 4.4 Graustufen H, V, M

Bento-Karten `ton: 'h'` (Anthrazit), `'v'` (Mittelgrau), `'m'` (Hellgrau), dazu `'u'` (Umriss) und `'w'` (Weiß). Text auf allen Tönen in `--hvm-ink`, Kontraste in Abschnitt 5. Weiße Schrift auf Anthrazit (3,55 : 1) wird nicht verwendet.

## 5. Kontraste (WCAG 2.2, relative Luminanz)

Berechnet mit der WCAG-Formel (sRGB linearisiert, L = 0,2126 R + 0,7152 G + 0,0722 B, Kontrast = (L1 + 0,05) / (L2 + 0,05)).

### 5.1 Matrix aller CI-Farben

| | Orange | Ink | Anthrazit | Mittelgrau | Hellgrau | Umriss | Weiß |
|---|---|---|---|---|---|---|---|
| Orange | 1,00 | 8,31 | 1,69 | 1,30 | 1,47 | 1,77 | 2,09 |
| Ink | 8,31 | 1,00 | 4,91 | 6,41 | 12,20 | 14,73 | 17,40 |
| Anthrazit | 1,69 | 4,91 | 1,00 | 1,31 | 2,49 | 3,00 | 3,55 |
| Mittelgrau | 1,30 | 6,41 | 1,31 | 1,00 | 1,90 | 2,30 | 2,71 |
| Hellgrau | 1,47 | 12,20 | 2,49 | 1,90 | 1,00 | 1,21 | 1,43 |
| Umriss | 1,77 | 14,73 | 3,00 | 2,30 | 1,21 | 1,00 | 1,18 |
| Weiß | 2,09 | 17,40 | 3,55 | 2,71 | 1,43 | 1,18 | 1,00 |

### 5.2 Eingesetzte Paare

| Vordergrund | Hintergrund | Kontrast | Einsatz | Anforderung |
|---|---|---|---|---|
| Ink | Weiß | 17,40 | Fließtext, Überschriften, Navigation | 4,5 erfüllt |
| Ink | Glas-Header (92 % Weiß über Ink, ca. #EDEDED) | ca. 14,9 | Navigation im kompakten Header | 4,5 erfüllt |
| Ink | Umriss | 14,73 | Flächensektionen, Hinweise, Tabellenzeilen | 4,5 erfüllt |
| Ink | Hellgrau | 12,20 | Bento M | 4,5 erfüllt |
| Ink | Mittelgrau | 6,41 | Bento V | 4,5 erfüllt |
| Ink | Anthrazit | 4,91 | Bento H | 4,5 erfüllt |
| Ink | Orange | 8,31 | Primärbutton, Platzhalter, Entwurfsband, Karten-Hover, Angebot in der mobilen Bar | 4,5 erfüllt |
| Weiß | Ink | 17,40 | Text in dunklen Sektionen, Buttons auf dunkel, Button-Hover | 4,5 erfüllt |
| Umriss | Ink | 14,73 | Einleitung im Hero und CTA, Adresse im Footer | 4,5 erfüllt |
| Hellgrau | Ink | 12,20 | Eyebrow im Hero, Kennzahl-Labels, Stichtag, Footer-Titel und Metazeile | 4,5 erfüllt |
| Ink mit 62 % Deckkraft (ca. #717171) | Weiß | ca. 4,88 | Platzhaltertext in Feldern | 4,5 erfüllt |
| Anthrazit | Weiß | 3,55 | Zahl 404 (ab 96 px, dekorativ, aria-hidden) | nur ab 24 px, erfüllt |
| Anthrazit (Rand) | Weiß (Feld) | 3,55 | Ränder von Eingabefeldern und Auswahlkarten | 3,0 für Bedienelemente erfüllt |
| Fokus innen Ink | Weiß | 17,40 | Fokusring auf hellem Grund | 3,0 erfüllt |
| Fokus innen Ink | Umriss | 14,73 | Fokusring auf Flächensektionen | 3,0 erfüllt |
| Fokus außen Orange | Ink | 8,31 | Fokusring auf dunklem Grund (innen dann Weiß) | 3,0 erfüllt |

Nicht als Text eingesetzt: Orange auf Weiß (2,09), Anthrazit unter 24 px auf Weiß, Mittelgrau und Hellgrau auf Weiß, Weiß auf Anthrazit. Kartenränder in Hellgrau (1,43) sind rein dekorativ, die Karten sind über Überschrift und Link bestimmt.

Automatische Prüfung: axe-core über Playwright auf `/`, `/styleguide/` und der 404-Seite in 1440 × 900 und 390 × 844 ohne serious oder critical Befunde (Skript im Scratch-Ordner der Designaufgabe, für `npm run e2e` zu übernehmen).

## 6. Komponenten (Twig-Makros)

Aufruf jeweils mit `{% import 'components/<datei>.html.twig' as x %}`. Alle Optionen sind optional, sofern nicht anders angegeben. Titel akzeptieren das Leitmotiv über Sternchen: `'Leistungen für *Eigentümer*'` ergibt ein fettes Schlüsselwort (der Text wird escaped).

| Makro | Datei | Aufruf und Optionen |
|---|---|---|
| Button | `button.html.twig` | `b.button(label, url, {variante: 'primaer' oder 'sekundaer' oder 'dunkel', icon: 'pfeil', klein, breit, deaktiviert, typ, klasse, attribute: [[name, wert]]})`. Ohne url entsteht ein `<button>`. Aliase `.c-button--primary` und `.c-button--secondary` für die Beispiele aus dem Architekturvertrag |
| Textlink | `button.html.twig` | `b.textlink(label, url, {icon})` |
| Eyebrow | `eyebrow.html.twig` | `e.eyebrow(text, {klasse})` |
| Titel | `titel.html.twig` | `t.text('Text mit *Schlüsselwort*')` |
| Section-Head | `section-head.html.twig` | `sh.section_head({eyebrow, titel, text, ebene: 2, id, geteilt: true, aktion: {label, url}})` |
| Karte | `card.html.twig` | `c.card({titel, text, url, ton, nummer, icon, eyebrow, fuss, zusatz, keil, gross, ebene, klasse: 'c-karte--kompakt', einblenden})`. `zusatz` nimmt Markup aus `{% set %}...{% endset %}` auf, z. B. Platzhalter |
| Bento | `bento.html.twig` | `bento.bento([{titel, text, url, ton, breite: 4 bis 8, hoch, gross, keil}], {ebene, klasse})` |
| Kennzahlen | `kennzahlen.html.twig` | `kz.kennzahlen([{wert, label, zaehler, vorsatz, text}], {stichtag, quelle})`. Stichtag wird immer angezeigt, wenn übergeben |
| Zeitleiste | `timeline.html.twig` | `tl.timeline([{titel, text}], {ebene})`, 3 bis 6 Schritte |
| FAQ | `faq.html.twig` | `faq.faq([{frage, antwort}], {offen: 1})`, `details` und `summary` |
| Formular | `form-fields.html.twig` | `f.eingabe`, `f.textfeld`, `f.auswahl`, `f.auswahlkarten({..., spalten: true})`, `f.checkbox`, `f.suche`, `f.fortschritt(wert)`, `f.stepper(schritte, aktuell)`. Gemeinsam: `name` (Pflicht), `label`, `id`, `wert`, `pflicht`, `optional`, `deaktiviert`, `hinweis`, `fehler`, `autocomplete`. Hinweis und Fehler sind über `aria-describedby` verknüpft, Fehler setzen `aria-invalid="true"` und haben Symbol und Textpräfix |
| CTA-Band | `cta-band.html.twig` | `cta.cta_band({titel, text, primaer: {label, url}, sekundaer: {label, url}, id})` |
| Hinweis | `notice.html.twig` | `n.hinweis(titel, text, {typ: 'info' oder 'warnung'})` |
| Platzhalter-Block | `placeholder.html.twig` | `ph.block(titel, text, {erklaerung, immer})`, nur außerhalb der Produktion, außer `immer: true` |

Weitere Muster ohne Makro (CSS-Klassen): `.c-tabelle` im Rahmen `.c-tabelle-rahmen` (mit `tabindex="0"`, `role="region"` und `aria-label` für horizontales Scrollen), `.c-prosa` für Artikel, `.c-hinweis`, `.c-breadcrumbs`, `.c-seitenkopf` (heller Seitenkopf mit Hausumriss), `.c-hero` (dunkler Hero), `.c-karten` und `.c-karten--4`.

Platzhalter im Fließtext: Twig-Funktion `placeholder('…')` erzeugt `.placeholder` (Orange mit gestrichelter Kontur, auch auf dunklem Grund deutlich sichtbar).

Partials: `header`, `footer`, `mobile-bar`, `draft-banner` (nur wenn `app.is_production` falsch und `page.freigabe.status` nicht `freigegeben`), `breadcrumbs` (aus `page.breadcrumbs`, ab zwei Einträgen), `kennlinie` (`variante`: 8, 24 oder 48), `hausumriss` (`dunkel`, `zeichnen`, `klasse`), `icon` (`name`: pfeil, chevron, menue, schliessen, telefon, dokument, notfall, portal, schaden, kontakt, suche, info, haus, fehler).

Layout-Blöcke: `title`, `meta`, `head_extra`, `body_class`, `breadcrumbs` (zusätzlich, Standard ist das Breadcrumb-Partial, leer überschreiben zum Ausblenden oder an eigener Stelle einbinden), `hero`, `content`, `scripts`.

## 7. Header, Navigation, Footer, mobile Bar

- Header fest positioniert (`position: fixed`), der Body hält die volle Headerhöhe frei. Der Wechsel in den kompakten Zustand ab 48 px Scrollweg verschiebt dadurch keinen Inhalt. Im kompakten Zustand Glas-Effekt mit 92 % deckendem Weiß und `backdrop-filter`, ohne Unterstützung reines Weiß.
- Logo: Vollversion `hvm-logo.svg` mit `width="144" height="125"` (Desktop 144 px, mobil 104 px breit), kompakt die Kurzform `hvm-kurzform.svg` mit `width="74" height="36"`. Das Logo wird nicht eingefärbt, nicht verzerrt und nur dezent eingeblendet. Der Linkname kommt aus `aria-label`, die Bilder haben `alt=""`.
- Hauptnavigation ab 1152 px: Untermenü Leistungen als Disclosure-Button (`aria-expanded`, `aria-controls`), Klick oder Enter öffnet, Escape schließt und setzt den Fokus zurück, Fokusverlust und Klick außerhalb schließen. Ohne JavaScript öffnet das Untermenü per `:focus-within` und Hover. Aktive Seite mit `aria-current="page"` und fetter Schrift, nicht nur über die Unterstreichung.
- Mobil und Tablet: Menü-Button mit `aria-expanded`, die Navigation öffnet als Vollfläche unter dem kompakten Header, Escape schließt. Ohne JavaScript ersetzt ein Sprunglink zur Footer-Navigation den Button.
- Footer dunkel, Kennlinie an der Oberkante, Logo auf weißer Schutzfläche (16 px Innenabstand, größer als der Schutzraum von 106/1143 der Logohöhe). Pflichtangaben aus `firma`: Name, Anschrift, E-Mail, Telefon oder Platzhalter, Amtsgericht, HRB, Geschäftsführer, Mitgliedschaften als Text.
- Mobile Bottom-Bar unter 768 px: Angebot, Anrufen (nur mit `firma.telefon` als tel-Link; ohne Nummer führt die Aktion zu `/kontakt/` und heißt deshalb „Kontakt“), Notfall.

## 8. Bewegung

- Scroll-getriebenes Einblenden über `animation-timeline: view()` mit `animation-range: entry 0% entry 55%` und 12 px Versatz für alle Elemente mit `data-einblenden`. Ohne Unterstützung setzt `einblenden.js` per IntersectionObserver `.is-sichtbar` (400 ms, `--ease`); ohne JavaScript bleibt alles sichtbar.
- Einmaliges Einblenden des Hero beim Laden (`.u-auftritt`, 500 ms, gestaffelt um 80 ms), Nachzeichnen des Hausumrisses.
- Zähler der Kennzahlen: einmal, sobald zu 60 % sichtbar, 1,4 s. Der Endwert steht im HTML, während der Animation liest ein Screenreader den Endwert aus einem unsichtbaren Text.
- View Transitions für Seitenwechsel: `@view-transition { navigation: auto; }`.
- `prefers-reduced-motion: reduce` schaltet alle Animationen und Übergänge ab, auch Zähler und Nachzeichnen. Im Druck sind alle Einblendungen deaktiviert.
- Kein Parallax, keine Karussells, keine Autoplay-Medien.

## 9. Entscheidungen

1. **Kein Dark Mode in dieser Phase.** MP 3.2 stellt ihn frei. Auf dunklem Grund dürfte das Logo nur auf heller Schutzfläche erscheinen, weil die Negativvariante nicht freigegeben ist (MP 3.8). Ein dunkles Farbschema würde den Header mit einer weißen Logofläche unterbrechen oder eine nicht freigegebene Logovariante erfordern. Dazu verdoppelt sich der Prüfaufwand aller Kontrastpaare. Dunkle Sektionen (Hero, Kennzahlen, CTA, Footer) liefern den Kontrast bereits im hellen Schema. `color-scheme: light` ist gesetzt.
2. **Kennlinie ohne clip-path:** der gewinkelte Verlauf mit korrigierten Stopps erzeugt die Schrägen exakt (Rechenweg 4.1). Das ist einfacher und in jeder Breite pixelgenau.
3. **Display-Mindestgröße 36 px unter 576 px Viewport** statt 48 px. Grund: deutsche Komposita wie „Hausverwaltung“ passen bei 48 px nicht in 358 px Satzbreite (390 px Gerät) und brechen ohne Silbentrennung mitten im Wort. Ab 576 px gilt die Spanne 48 bis 96 px aus MP 3.3. Bitte freigeben oder verwerfen.
4. **Hero-Headline als `hgroup`:** `<h1>Hausverwaltung für Eigentümer und Investoren</h1><p>, <strong>strukturiert</strong> und nachvollziehbar.</p>`, beide Teile fließen inline als eine Headline. Grund: Der bestehende Test `PageControllerTest` verlangt ein `<h1>` ohne Attribute und ohne Kindelemente (Regex `<h1>[^<]+</h1>`). Ein `<strong>` direkt im `<h1>` wäre semantisch einfacher. Empfehlung an die Test-Verantwortlichen: Regex auf `#<h1[^>]*>.+?</h1>#s` lockern, dann kann das Schlüsselwort in das `<h1>` wandern.
5. **Header fest statt sticky:** verhindert Layoutsprünge beim Kompaktwechsel und Scroll-Anchoring-Schleifen.
6. **Logogröße im Header:** Vollversion 144 px breit auf dem Desktop (über der Lesbarkeitsgrenze von rund 140 px aus `docs/logo/geometrie.md`), mobil 104 px. Mobil ist die Wortmarke damit kleiner als empfohlen. Eine freigegebene horizontale Logovariante würde das lösen (offener Punkt).
7. **Auswahlkarten standardmäßig untereinander**, nebeneinander nur mit `spalten: true`, weil lange Begriffe wie „Eigentümergemeinschaften“ in schmalen Spalten sonst brechen.
8. **Kundenstimmen:** `config/kundenstimmen.php` ist nicht als Twig-Variable verfügbar. Die Startseite liest `kundenstimmen|default([])`; solange die Variable fehlt oder leer ist, bleibt der Abschnitt in der Produktion ausgeblendet und zeigt außerhalb einen Platzhalter. Zum Aktivieren genügt eine Zeile in `src/View/View.php`: `$this->twig->addGlobal('kundenstimmen', $config->array('kundenstimmen'));`.
9. **`theme-color` Weiß** statt Ink, passend zum hellen Header.
10. **Icons** als eigene, schlichte Linienpfade (keine fremde Icon-Bibliothek, keine Lizenzfrage).

## 10. Offene Punkte

- Freigabe des Designsystems durch die Geschäftsführung (MP Phase 2).
- Entscheidung 3 (Display-Mindestgröße) und 4 (Test-Regex) bestätigen.
- Horizontale Logovariante oder Freigabe der Negativvariante (für Header mobil, Footer und einen späteren Dark Mode).
- Aussagen mit Kennzeichnung auf der Startseite: `[Portal-Adresse bestätigen]`, `[24/7-Notdienst bestätigen]`, `[zentrale Telefonnummer bestätigen]` im Footer, `[Kundenstimmen mit belegbarer Herkunft ergänzen]`.
- Echte Silbentrennung hängt vom Browser ab (`hyphens: auto` mit `lang="de"`). In Umgebungen ohne deutsches Trennwörterbuch bricht `overflow-wrap` lange Wörter ohne Trennstrich um.
