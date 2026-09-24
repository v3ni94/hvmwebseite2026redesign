# Weiterleitungen Altseite

Stand: 24.09.2026. Umsetzung: `config/redirects.php`, ausgewertet von `Hvm\Http\Middleware\LegacyRedirects` vor der Trailing-Slash-Prüfung (eine Weiterleitung, keine Kette).

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
| `/monheim/` | `/hausverwaltung-monheim-am-rhein/` | 301 | exakt | belegt | Stadtseite des Hauptsitzes (config/staedte.php) |
| `/muenchen/` | `/hausverwaltung-muenchen/` | 301 | exakt | belegt | passende Stadtseite |
| `/bernau-bei-berlin/` | `/hausverwaltung-berlin/` | 301 | exakt | belegt | Bernau bei Berlin liegt im PLZ-Bereich 10000 bis 16999 (Stadtseite Berlin) |
| `/konstanz/` | `/hausverwaltung-konstanz/` | 301 | exakt | belegt | passende Stadtseite |
| `/berlin/` | `/hausverwaltung-berlin/` | 301 | exakt | vermutet | passende Stadtseite |
| `/hameln/` | `/hausverwaltung-hannover/` | 301 | exakt | vermutet | PLZ 31787 liegt im Bereich 29000 bis 33999 (Stadtseite Hannover) |
| `/koeln/` | `/hausverwaltung-koeln/` | 301 | exakt | vermutet | passende Stadtseite |
| `/koeln-bonn/` | `/hausverwaltung-koeln/` | 301 | exakt | vermutet | frühere Sammelregion Köln / Bonn, Anschrift der Altseite in Köln |
| `/duesseldorf/` | `/hausverwaltung-duesseldorf/` | 301 | exakt | vermutet | passende Stadtseite |
| `/hamburg/` | `/hausverwaltung-hamburg/` | 301 | exakt | vermutet | passende Stadtseite |
| `/hannover/` | `/hausverwaltung-hannover/` | 301 | exakt | vermutet | passende Stadtseite |
| `/frankfurt/` | `/hausverwaltung-frankfurt-am-main/` | 301 | exakt | vermutet | passende Stadtseite |
| `/essen/` | `/hausverwaltung-essen/` | 301 | exakt | vermutet | passende Stadtseite |
| `/kassel/` | `/hausverwaltung-kassel/` | 301 | exakt | vermutet | passende Stadtseite |
| `/erkelenz/` | `/hausverwaltung-erkelenz/` | 301 | exakt | vermutet | passende Stadtseite (PLZ-Bereich 41000 bis 44999) |
| `/portfolio/` und Unterpfade | `/referenzen/` | 301 | Präfix | belegt | Referenz `/portfolio/mfh-moenchengladbach/` bekannt, Referenzen nur mit Freigabe (MP 5) |
| `/ff/immobilien/` und Unterpfade | `/verkauf/` | 302 | Präfix | belegt | Exposés aus externem Maklersystem, vorläufig bis `[Datenquelle Immobilienangebote klären]`, danach 301 auf das Ziel der Schnittstelle |
| `/datenschutzerklaerung/` | `/datenschutz/` | 301 | exakt | vermutet | Footer-Link „Datenschutzerklärung“, Slug nicht abrufbar gewesen (Bestandsaufnahme 3.9) |
| `/wp-login.php` | entfällt | 410 | exakt | belegt | WordPress entfällt |
| `/wp-admin/` und Unterpfade | entfällt | 410 | Präfix | belegt | WordPress entfällt |
| `/xmlrpc.php` | entfällt | 410 | exakt | belegt | WordPress entfällt |
| `/feed/` | entfällt | 410 | exakt | belegt | WordPress entfällt, kein Feed geplant |

URLs, die unverändert bleiben (keine Weiterleitung): `/vermietung/`, `/verkauf/`, `/betreuungsgebiete/`, `/impressum/`. Die Stadtseiten `/hausverwaltung-<slug>/` sind neu und werden nicht umgeleitet (die exakte Regel `/hausverwaltung/` erfasst sie nicht).

Wichtig vor Livegang: Die Ziele der Stadtweiterleitungen sind Stadtseiten aus `content/staedte/`. Solange eine Stadtseite nicht freigegeben ist (`freigabe: nein`), liefert sie in Produktion 404, die Weiterleitung liefe dann ins Leere. Vor dem Livegang daher mindestens die Stadtseiten Monheim am Rhein, München, Berlin, Konstanz, Hannover, Köln, Düsseldorf, Hamburg, Frankfurt am Main, Essen, Kassel und Erkelenz freigeben. `tests/Unit/Http/LegacyRedirectsTest.php` prüft, dass jedes Ziel als Stadtseite existiert (mit SHOW_DRAFTS), `tests/e2e/redirects.spec.js` Status und Ziel jeder Regel.

Offen:

- Vollständige URL-Liste der Altseite (Mirror war aus der Entwicklungsumgebung nicht möglich, siehe `docs/phase0.md`). Nach Vorliegen Tabelle ergänzen, vermutete Einträge bestätigen oder entfernen.
- Weitere Portfolio-Referenzen und Exposé-Adressen: durch Präfixregeln abgedeckt.
- Uploads der Altseite (`/wp-content/uploads/...`): bewusst ohne Regel, liefern 404. Bei nennenswerten externen Verweisen (z. B. auf Bilder) nach Livegang im 404-Monitoring bewerten.
- Nach Livegang vier Wochen 404-Monitoring (MP 13, Phase 7).
