
# Technische Nachzeichnung, freigegeben durch die Geschäftsführung am 24.09.2026 (docs/auftraggeber-angaben.md). Originalvektordatei beim früheren Dienstleister weiterhin anfordern.

Stand: 23.09.2026. Grundlage: `docs/Logo_HVM.jpg` (offizielles Logo, 1320 x 1143 px), MP Abschnitt 3.4 und 3.8, `docs/architektur.md` Abschnitt 7.

## 1. Methode

1. Pixelanalyse mit Python (Pillow, numpy): Farbflächen wurden per Farbabstands-Klassifikation (nächste Referenzfarbe je Pixel) segmentiert. Kanten wurden anschließend subpixelgenau aus dem Antialiasing bestimmt: für jede Kante wurde entlang von Zeilen oder Spalten die Projektion der Pixelfarbe auf die Verbindungsachse zweier angrenzender Flächenfarben berechnet und der Nulldurchgang bei 50 % linear interpoliert. Aus vielen so bestimmten Punkten je Kante wurde je eine Gerade per kleinster Quadrate gefittet (typische Residuen 0,02 bis 0,3 px, siehe Beispiele in `docs/logo/geometrie.md`). Eckpunkte der Polygone ergeben sich als Schnittpunkte der Geraden.
2. Ein 1 bis 2 px breiter Bildrahmen der JPG-Datei (Randfarbe ca. #E9E9EB) wurde als Kompressionsartefakt erkannt und nicht in die Geometrie übernommen (vor der Analyse auf Hintergrundweiß neutralisiert).
3. Die geometrischen Flächen (Haus, H, V, Keil, M) wurden als reine Polygone (`<path>` mit geraden Segmenten) im Koordinatenraum `viewBox="0 0 1320 1143"` nachgebaut, Hintergrund transparent.
4. Die Wortmarke „HAUSVERWALTUNG MÜLLER" wurde separat verarbeitet: Ausschnitt aus der Vorlage, 6-fache Hochskalierung (Lanczos), Schwellwert-Binarisierung, Vektorisierung mit `potrace` (Parameter `--flat -a 0.3 -O 0.2`), Rücktransformation der Pfade in den Logo-Koordinatenraum über eine Gruppentransformation (`translate` + `scale 1/6`).
5. Prüfung: Rendering des fertigen SVG in exakt 1320 x 1143 px über Chromium (Playwright, `executablePath` auf den lokal bereitgestellten Chromium-Build), Vergleich gegen die JPG-Vorlage über mittlere absolute Abweichung, 99. Perzentil und IoU je Farbregion (Nächste-Referenzfarbe-Klassifikation, je Element auf einen bounding-nahen Bildausschnitt beschränkt, um Antialiasing-Rauschen benachbarter, farblich ähnlicher Elemente nicht fälschlich der geprüften Fläche zuzurechnen).

## 2. Farbzuordnung

| Fläche | Gemessene Farbe (Ø, entrauscht) | Nächste CI-Farbe | Abweichung | Verwendet |
|---|---|---|---|---|
| Hausumriss | #ECECEC (236,236,236) | `--hvm-umriss` #ECECEC | 0 | CI-Wert |
| H (Anthrazit) | #868789 (134 bis 135,135 bis 136,137 bis 138) | `--hvm-anthrazit` #87888A | ≤ 1 je Kanal | CI-Wert |
| V, linker Schenkel | #9C9D9F (156,157,159) | `--hvm-mittelgrau` #9C9D9F | 0 | CI-Wert |
| V, oberer/rechter Bereich (inkl. Überlagerungsfläche zu M) | **#B0B1B3** (176,177,179) | am nächsten: Mittelgrau #9C9D9F | ~20 Stufen je Kanal (zu Hellgrau #D7D8DA ~39 Stufen) | **gemessener Wert, keine CI-Farbe** |
| Keil | #E8A83A (232,168,58) | `--hvm-orange` #E6A83C | ≤ 2 je Kanal | CI-Wert |
| M | #D7D8DA (215,216,218) | `--hvm-hellgrau` #D7D8DA | 0 | CI-Wert |
| Wortmarke | #181619 (24,22,25) | `--hvm-ink` #1A1A1A | ≤ 5 je Kanal | CI-Wert |
| Hintergrund | ca. #FDFDFD (253,253,253), JPEG-Kompression von reinem Weiß | `--hvm-weiss` #FFFFFF | ≤ 2 je Kanal | im SVG transparent, keine Fläche |

**Besonderheit V/M-Überlagerung**: Das Logo zeigt zwischen dem rechten V-Schenkel, dem Keil und dem linken M-Schenkel eine zusätzliche, in keiner CI-Farbe enthaltene Fläche (gemessen #B0B1B3). Sie entsteht dort, wo sich V- und M-Form im Original sichtbar überlagern ("mit Überlagerungen", MP 3.4). Da Bildtreue vor Farbnormierung geht, wurde dieser gemessene Wert unverändert in `hvm-logo.svg` und `hvm-kurzform.svg` übernommen und hier dokumentiert. Für eine spätere, freigegebene Fassung sollte die Geschäftsführung bzw. der Dienstleister klären, ob dieser Farbton bewusst gewählt oder ein Kompressions-/Renderartefakt der Vorlage ist.

## 3. Messwerte der Prüfung (aktueller Stand)

Rendering: Chromium/Playwright, 1320 x 1143 px, Vergleich gegen `docs/Logo_HVM.jpg`.

- Mittlere absolute Abweichung (über alle Pixel, 0 bis 255-Skala): **2,60**
- 99. Perzentil der absoluten Abweichung: **37,0**

IoU je Farbregion (Nächste-Referenzfarbe-Klassifikation, auf einen Bildausschnitt je Element beschränkt):

| Region | IoU | Ziel | Erreicht |
|---|---|---|---|
| Hausumriss | 0,963 | ≥ 0,98 | Nein |
| H | 0,978 | ≥ 0,98 | Knapp nein |
| V, linker Schenkel | 0,963 | ≥ 0,98 | Nein |
| V, oberer/rechter Bereich | 0,969 | ≥ 0,98 | Nein |
| Keil | 0,983 | ≥ 0,98 | **Ja** |
| M | 0,979 | ≥ 0,98 | Knapp nein |
| Wortmarke | 0,937 | ≥ 0,93 | **Ja** |

Differenzbild: `docs/logo/nachzeichnung-diff.png` (Abweichung je Pixel, verstärkt dargestellt), Überlagerungsbild: `docs/logo/nachzeichnung-overlay.png` (50/50-Überblendung Original/Nachzeichnung), Rendering der Nachzeichnung allein: `docs/logo/nachzeichnung-render.png`.

## 4. Begründung, warum das 0,98-Ziel bei einzelnen Flächen nicht erreicht wurde

Nach mehreren Iterationen (grobe Kontur-Extraktion, anschließend subpixelgenaue Kantenmessung per linearer Interpolation und Geradenfit für H, V, Keil und die wesentlichen Hausumriss-Kanten) liegen alle geometrischen Flächen im Bereich 0,96 bis 0,98 IoU. Eine weitere Annäherung an 0,98 ist mit vertretbarem Aufwand nicht mehr zu erzielen, weil:

- Die Vorlage ist ein **JPEG** mit sichtbarer Kompression (u. a. der 1 bis 2 px breite Bildrahmen, siehe Abschnitt 1). Die Kantenglättung (Antialiasing) der Vorlage und die Kantenglättung des Chromium-Renderers folgen unterschiedlichen, nicht dokumentierten Algorithmen. Dadurch verbleibt ein systematischer, umlaufender Saum von rund 1 bis 2 px Breite an praktisch jeder Kante, der von der Nächste-Farbe-Klassifikation je nach Seite unterschiedlich zugeordnet wird. Bei einer Fläche mit langem Umfang (z. B. Hausumriss: doppelte Kontur aus Dach- und Wandlinie, insgesamt mehrere tausend Pixel Umfang) summiert sich dieser Saum zu einigen Prozent Flächenabweichung, ohne dass die zugrunde liegende Geometrie noch messbar falsch wäre.
- Für den Hausumriss ließ sich die rechte, kurze Dachschräge (Spitze bis zum offenen Ende) nur mit deutlich größerer Messunsicherheit fitten als die linke Dachschräge und die Wand (Residuen der Geradenfits dort 25 bis 32 px statt 0,1 bis 0,2 px bei den übrigen Kanten). Die entsprechenden Eckpunkte wurden daher aus der ursprünglichen Konturerkennung übernommen und nur die übrigen, gut vermessenen Kanten (Dach links, Wand außen/innen) verfeinert.
- Bei H, V und M liegt die verbleibende Abweichung nahezu vollständig als schmaler, gleichmäßig um die gesamte Kontur verteilter Saum vor (siehe Kontrollauswertung während der Iteration), nicht als lokal konzentrierter Form- oder Lagefehler. Das spricht für eine durch Kompression/Antialiasing bedingte Grenze und nicht für eine falsch vermessene Kante.

Die Wortmarke wurde zusätzlich über die Schwelle der Binarisierung vor der Vektorisierung kalibriert (Vergleich mehrerer Schwellwerte gegen die gemessene Ink-Pixelzahl der Vorlage) und erreicht mit 0,937 das geforderte Ziel von 0,93.

## 5. Offene Punkte

- **Freigabe erteilt (24.09.2026).** Die Geschäftsführung hat die Nachzeichnung freigegeben, sie darf live gehen. Die Originalvektordatei beim früheren Dienstleister anzufordern bleibt empfehlenswert, ist aber nicht blockierend.
- Die gemessene, CI-fremde Fläche `#B0B1B3` (Abschnitt 2) sollte vor einer Freigabe fachlich geklärt werden (bewusste Gestaltung vs. Artefakt).
- Bei sehr kleinen Darstellungsgrößen (Favicon, 16 bis 24 px) verliert `--hvm-hellgrau` (M-Fläche) auf hellem Grund sichtbar an Kontrast; dies folgt aus der ohnehin in MP 3.2 dokumentierten Kontrastregel („Anthrazit #87888A auf Weiß nur ab 24 px oder dekorativ") und ist keine Fehlmessung, sollte aber bei der Freigabe der Favicon-Variante berücksichtigt werden.
- Keine Negativvariante erstellt (wie in MP 3.8 gefordert, erst nach Freigabe).
