# Weiterleitungen Altseite

Stand: 22.09.2026. Umsetzung: `config/redirects.php`, ausgewertet von `Hvm\Http\Middleware\LegacyRedirects` vor der Trailing-Slash-Prüfung (eine Weiterleitung, keine Kette).

Regeln der Auswertung:

- Vergleich ohne Beachtung von Groß- und Kleinschreibung, mit oder ohne abschließenden Schrägstrich (`/gutachten` und `/gutachten/` führen direkt zu `/wertgutachten/`).
- Exakte Treffer vor Präfixen, längere Präfixe vor kürzeren. Ein Präfix erfasst den Pfad selbst und alle Unterpfade, nicht aber ähnliche Pfade (`/portfolio-alt/` bleibt unberührt).
- Der Query-String wird übernommen (UTM-Parameter aus Anzeigen bleiben erhalten).
- 301 mit `Cache-Control: public, max-age=86400`, 302 mit `no-store`, 410 mit kurzer Hinweisseite.

Verifikationsstatus: **belegt** = in der Bestandsaufnahme (Abschnitte 2, 3) genannt oder WordPress-Standard. **vermutet** = aus dem Muster der Standortseiten abgeleitet, per Mirror der Altseite oder Google Search Console (Bericht Seiten, Filter 404 nach Livegang) verifizieren.

| Alte URL | Neue URL | Status | Typ | Verifikation | Begründung |
|---|---|---|---|---|---|
| `/hausverwaltung/` | `/weg-verwaltung/` | 301 | exakt | belegt | Menüpunkt Hausverwaltung der Altseite, fachlich am nächsten an der WEG-Verwaltung |
| `/gutachten/` | `/wertgutachten/` | 301 | exakt | belegt | Leistung umbenannt, sprechender Slug |
| `/monheim/` | `/betreuungsgebiete/` | 301 | exakt | belegt | Standortseiten werden nicht nachgebaut (MP 5), Sammelseite |
| `/muenchen/` | `/betreuungsgebiete/` | 301 | exakt | belegt | wie oben |
| `/bernau-bei-berlin/` | `/betreuungsgebiete/` | 301 | exakt | belegt | wie oben |
| `/konstanz/` | `/betreuungsgebiete/` | 301 | exakt | belegt | wie oben |
| `/berlin/` | `/betreuungsgebiete/` | 301 | exakt | vermutet | Region der Altseite, Slug nach Muster |
| `/hameln/` | `/betreuungsgebiete/` | 301 | exakt | vermutet | wie oben |
| `/koeln/` | `/betreuungsgebiete/` | 301 | exakt | vermutet | wie oben |
| `/koeln-bonn/` | `/betreuungsgebiete/` | 301 | exakt | vermutet | wie oben |
| `/duesseldorf/` | `/betreuungsgebiete/` | 301 | exakt | vermutet | wie oben |
| `/hamburg/` | `/betreuungsgebiete/` | 301 | exakt | vermutet | wie oben |
| `/hannover/` | `/betreuungsgebiete/` | 301 | exakt | vermutet | wie oben |
| `/frankfurt/` | `/betreuungsgebiete/` | 301 | exakt | vermutet | wie oben |
| `/essen/` | `/betreuungsgebiete/` | 301 | exakt | vermutet | wie oben |
| `/kassel/` | `/betreuungsgebiete/` | 301 | exakt | vermutet | wie oben |
| `/erkelenz/` | `/betreuungsgebiete/` | 301 | exakt | vermutet | früherer Sitz, kein Geschäftssitz mehr |
| `/portfolio/` und Unterpfade | `/referenzen/` | 301 | Präfix | belegt | Referenz `/portfolio/mfh-moenchengladbach/` bekannt, Referenzen nur mit Freigabe (MP 5) |
| `/ff/immobilien/` und Unterpfade | `/verkauf/` | 302 | Präfix | belegt | Exposés aus externem Maklersystem, vorläufig bis `[Datenquelle Immobilienangebote klären]`, danach 301 auf das Ziel der Schnittstelle |
| `/datenschutzerklaerung/` | `/datenschutz/` | 301 | exakt | vermutet | Footer-Link „Datenschutzerklärung“, Slug nicht abrufbar gewesen (Bestandsaufnahme 3.9) |
| `/wp-login.php` | entfällt | 410 | exakt | belegt | WordPress entfällt |
| `/wp-admin/` und Unterpfade | entfällt | 410 | Präfix | belegt | WordPress entfällt |
| `/xmlrpc.php` | entfällt | 410 | exakt | belegt | WordPress entfällt |
| `/feed/` | entfällt | 410 | exakt | belegt | WordPress entfällt, kein Feed geplant |

URLs, die unverändert bleiben (keine Weiterleitung): `/vermietung/`, `/verkauf/`, `/betreuungsgebiete/`, `/impressum/`.

Offen:

- Vollständige URL-Liste der Altseite (Mirror war aus der Entwicklungsumgebung nicht möglich, siehe `docs/phase0.md`). Nach Vorliegen Tabelle ergänzen, vermutete Einträge bestätigen oder entfernen.
- Weitere Portfolio-Referenzen und Exposé-Adressen: durch Präfixregeln abgedeckt.
- Uploads der Altseite (`/wp-content/uploads/...`): bewusst ohne Regel, liefern 404. Bei nennenswerten externen Verweisen (z. B. auf Bilder) nach Livegang im 404-Monitoring bewerten.
- Nach Livegang vier Wochen 404-Monitoring (MP 13, Phase 7).
