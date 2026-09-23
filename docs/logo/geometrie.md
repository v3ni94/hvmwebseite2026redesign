# HVM-Logo: gemessene Geometrie

Stand: 23.09.2026. Grundlage: pixelbasierte Vermessung von `docs/Logo_HVM.jpg` (1320 x 1143 px) mittels Python (Pillow, numpy) mit subpixelgenauer Kantenbestimmung aus dem Antialiasing (lineare Interpolation entlang der Farbachse zwischen zwei angrenzenden Flächenfarben, Nulldurchgang bei 50 % Mischanteil). Alle Koordinaten in Logo-Einheiten (1 Einheit = 1 px der Vorlage = 1 Einheit im SVG-`viewBox="0 0 1320 1143"`).

## 1. Winkel der Diagonalen

Winkel jeweils gegen die Vertikale gemessen (0 Grad = senkrecht), auf zwei Nachkommastellen.

| Element | Kante (Stützpunkte) | Winkel |
|---|---|---|
| **Keil (Orange)**, rechte/schräge Kante | (935,0; 444,5) → (800,6; 806,0) | **20,40 Grad** |
| V, linker Schenkel (H/V-Grenzlinie) | (466,8; 444,6) → (595,2; 786,0) | 20,77 Grad |
| V, oberer linker Schenkel (Vblue) | (605; 447) → (703; 752) | 17,81 Grad |
| V, oberer rechter Schenkel (Vblue) | (805; 446) → (703; 752) | 18,43 Grad |
| M, 1. Diagonale (links, absteigend) | (934; 446) → (785; 845) | 20,48 Grad |
| M, 2. Diagonale (Tal zu Spitze) | (910; 550) → (995; 874) | 14,70 Grad |
| M, 3. Diagonale (Spitze zu Tal) | (1093; 874) → (1178; 549) | 14,66 Grad |
| M, 4. Diagonale (rechts, aufsteigend) | (1178; 549) → (1287; 873) | 18,59 Grad |

**Empfehlung für Token `--hvm-keil-winkel`: 20,4 Grad** (gemessener Wert der Keil-Diagonale). Die V- und M-Diagonalen streuen zwischen rund 14,7 und 20,8 Grad; es handelt sich nicht um einen einzigen einheitlichen Winkel im Logo, sondern um mehrere, ähnliche, aber nicht identische Schrägen (siehe Werte oben). Für ein abgeleitetes, wiederkehrendes Gestaltungsmotiv (Kennlinie, Hover-Wischeffekt) ist der Keil-Winkel (20,4 Grad) der eindeutigste Referenzwert, da der Keil im Original ein einzelnes, sauber begrenztes Element ist.

Hinweis: Der CI-Skill (`hvm-ci`) legt für die CSS-Kennlinie (MP 3.4.1) die Schräge unabhängig als `0,9 × Bandhöhe` fest. Das ist ein gestalterisch vereinfachter, eigener Wert für das Kennlinien-Band und muss nicht mit dem hier gemessenen Logo-Winkel übereinstimmen; beide Werte sind bewusst getrennt zu betrachten.

## 2. Breitenanteile der vier Hauptsegmente (H / V / Keil / M)

Gemessen als horizontale Ausdehnung der sichtbaren Flächen von der linken Außenkante des H bis zur rechten Außenkante des M (Gesamtbreite 1106,0 Einheiten):

| Segment | Breite (Einheiten) | Anteil |
|---|---|---|
| H (Anthrazit) | 413,0 | 37,3 % |
| V (Mittelgrau + Überlagerungston) | 206,5 | 18,7 % |
| Keil (Orange) | 134,5 | 12,2 % |
| M (Hellgrau) | 352,0 | 31,8 % |

Zum Vergleich: Die CSS-Kennlinie (MP 3.4.1, CI-Skill) verwendet die idealisierten Stufen 0 bis 40 % Anthrazit, 40 bis 60 % Mittelgrau, 60 bis 67,5 % Orange, 67,5 bis 100 % Hellgrau. Die am Logo gemessenen Anteile (37,3 / 18,7 / 12,2 / 31,8) liegen in ähnlicher Größenordnung, weichen aber ab, da die Kennlinie ein eigenständiges, vereinfachtes Gestaltungselement ist und keine 1:1-Kopie der Logo-Proportionen sein muss.

## 3. Schutzraum

Für `hvm-kurzform.svg` wurde ein Schutzraum von **106 Logo-Einheiten** auf allen vier Seiten angewendet. Referenzmaß: Höhe des Querstrichs des H (594,9 bis 700,9 Einheiten = 106,0 Einheiten), wie in MP 3.8 gefordert ("Schutzraum mindestens die Höhe des M-Querstrichs"; das M besitzt keinen echten horizontalen Querstrich, daher wurde ersatzweise die Höhe des H-Querstrichs als Referenz herangezogen, siehe `docs/logo/nachzeichnung-pruefung.md`).

Resultierender `viewBox` der Kurzform: `75,0 338,6 1318,0 642,9` (x, y, Breite, Höhe).

## 4. Empfohlene Mindestgrößen im Web

Empfehlungen auf Basis der sichtbaren Detailgrößen (Wortmarken-Strichstärke, Keil-Breite, Überlagerungssliver):

- **Vollversion** (Haus + HVM + Wortmarke): Mindestbreite **180 px**. Unterhalb von rund 140 px wird die Wortmarke „HAUSVERWALTUNG MÜLLER" nicht mehr zuverlässig lesbar (Versalhöhe der Wortmarke sinkt unter ca. 7 bis 8 px).
- **Kurzform** (H, V, Keil, M ohne Haus/Wortmarke): Mindestbreite **48 px** für klare Lesbarkeit aller vier Segmente inkl. Keil und Überlagerungssliver.
- **Favicon-Variante**: ab **16 px** einsetzbar, allerdings nur noch als farblich gestuftes Icon erkennbar, nicht mehr buchstabengenau lesbar (siehe Einschränkung zu `--hvm-hellgrau` in `docs/logo/nachzeichnung-pruefung.md`, Abschnitt Kontrastprüfung).
