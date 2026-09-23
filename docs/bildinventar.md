# Bildinventar

Grundlage: `docs/bestandsaufnahme-muellerhv-de.md` Abschnitt 6 und `docs/quellen/masterprompt-relaunch-muellerhv-de.md`
Abschnitt 3.7. Lizenzstatus vor jeder Übernahme klären, ungeklärte Bilder nicht verwenden.
Spalte „Nutzung im Relaunch“ bleibt auf „nein bis geklärt“, solange der Lizenzstatus nicht
„eigenes Foto“, „Stock mit Lizenz“ oder „CC mit Namensnennung“ lautet.

Vollständige Rohdaten aus dem Mirror liegen nach einem Lauf von `bin/legacy-inventory.php`
unter `docs/legacy/bildinventar.md`. Diese Tabelle hier ist der bereinigte, mit der
Geschäftsführung abzustimmende Stand zur Übernahme.

## Von außen bekannte Bilder (Stand 23.09.2026)

| Datei | Verwendung | Abmessungen | Lizenzstatus | Nutzung im Relaunch |
|---|---|---|---|---|
| Titelbild Konstanz (Münster Konstanz) | Standortseite Konstanz | placeholder('Abmessungen aus Mirror ergänzen') | CC BY-SA 4.0, Holger Uwe Schmitt, Namensnennung Pflicht | nein bis geklärt (Namensnennung im Relaunch technisch umsetzen, dann ja) |
| /wp-content/uploads/2023/01/Berlin.jpg | Open-Graph-Bild Betreuungsgebiete | placeholder('Abmessungen aus Mirror ergänzen') | ungeklärt | nein bis geklärt |
| /wp-content/uploads/2023/01/Verkauf.jpg | Open-Graph-Bild Verkauf | 2048 x 2048 | ungeklärt | nein bis geklärt |
| /wp-content/uploads/2024/07/vermietung.webp | Open-Graph-Bild Vermietung | 1000 x 1000 | ungeklärt | nein bis geklärt |
| HVM-Logo (JPG) | Kopf, Footer | 1320 x 1143 (Seitenverhältnis strikt einzuhalten laut MP 3.8) | eigenes Asset, offizielle Datei der Geschäftsführung | ja, als offizielles Asset. SVG-Nachzeichnung in Prüfung, siehe unten |
| VZIV-Logo | Footer | placeholder('Abmessungen ergänzen') | Verbandslogo, Nutzung gemäß VZIV-Richtlinien | nein bis geklärt (Nutzungsrichtlinie prüfen) |
| IVD-Logo | Footer | placeholder('Abmessungen ergänzen') | Verbandslogo, Nutzung gemäß IVD-Richtlinien | nein bis geklärt (Nutzungsrichtlinie prüfen) |
| Standortbilder (13 Standorte laut Betreuungsgebiete) | Betreuungsgebiete | placeholder('aus Mirror ergänzen') | ungeklärt | nein bis geklärt |

## Logo (MP 3.8)

Das Logo liegt nur als JPG vor (Seitenverhältnis 1320 x 1143). Für das Web wird eine SVG
benötigt. Vorgehen laut Masterprompt:

1. Originalvektor beim früheren Dienstleister anfordern: placeholder('Vektordatei Logo anfordern').
2. Falls nicht verfügbar: technische Nachzeichnung als SVG aus der geometrischen Form,
   pixelgenau gegen das JPG geprüft. Status: in Prüfung, noch keine Freigabe.
3. Vor Livegang: Freigabe der Geschäftsführung für die Nachzeichnung einholen (Eskalation,
   siehe Organisationsvorgaben).
4. Varianten: Vollversion (Haus, HVM, Wortmarke), Kurzform HVM für Favicon und kompakten
   Header, negative Variante für dunkle Flächen nur mit gesonderter Freigabe.
5. Logo nicht einfärben, nicht verzerren, nicht animieren außer dezentem Einblenden.

## Bildsprache (MP 3.7)

Echte Objekte aus dem Bestand, Architekturdetails, Treppenhäuser, Fassaden, einheitliches
Grading (leicht entsättigt, warme Lichter passend zum Orange der Kennlinie). Alle Bilder als
AVIF und WebP mit `srcset`, `loading="lazy"` außer beim Hero-Bild, feste Seitenverhältnisse
gegen Layout-Sprünge.

## Offene Punkte

- Vollständige Dateinamen, Alt-Texte und Seitenverwendung der Bilder sind von außen nicht
  auslesbar. Ermittlung erfolgt über `bin/legacy-mirror.sh` und `bin/legacy-inventory.php` auf
  einem Rechner ohne Domainbeschränkung, siehe `docs/betrieb.md`.
- Lizenzstatus jedes Bildes vor Übernahme mit der Geschäftsführung klären und hier eintragen.
- Freigabe der Logo-SVG-Nachzeichnung vor Livegang einholen.
