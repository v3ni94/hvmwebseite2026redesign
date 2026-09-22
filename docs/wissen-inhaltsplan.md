# Inhaltsplan Wissen und FAQ

Stand: 23.09.2026. Grundlage: Masterprompt Abschnitt 7. Alle Inhalte sind Entwürfe (`freigabe: nein`) und werden erst nach Freigabe durch die Geschäftsführung veröffentlicht.

## 1. Artikel und Slugs (verbindlich für interne Verlinkung)

| Zielgruppe (`zielgruppe`) | Slug | Arbeitstitel | Passende Leistung (`leistung`) | CTA (`cta`) |
|---|---|---|---|---|
| weg-beirat | verwalterwechsel-weg | Verwalterwechsel in der WEG: Ablauf, Zeitplan, Unterlagen | /verwalterwechsel/ | angebot |
| weg-beirat | verwalter-bestellung-abberufung | Bestellung und Abberufung des Verwalters | /verwalterwechsel/ | angebot |
| weg-beirat | eigentuemerversammlung | Die Eigentümerversammlung: Einberufung, Ablauf, Niederschrift | /weg-verwaltung/ | angebot |
| weg-beirat | wirtschaftsplan-jahresabrechnung | Wirtschaftsplan, Jahresabrechnung und Vermögensbericht | /weg-verwaltung/ | angebot |
| weg-beirat | erhaltungsruecklage | Die Erhaltungsrücklage | /weg-verwaltung/ | angebot |
| weg-beirat | beschlussfassung-weg | Beschlussfassung in der Gemeinschaft der Wohnungseigentümer | /weg-verwaltung/ | angebot |
| weg-beirat | umlaufbeschluss | Der Umlaufbeschluss | /weg-verwaltung/ | angebot |
| weg-beirat | zertifizierter-verwalter | Der zertifizierte Verwalter | /weg-verwaltung/ | angebot |
| vermieter-investoren | mietverwaltung-leistungen | Mietverwaltung: Aufgaben und Leistungsumfang | /mietverwaltung/ | angebot |
| vermieter-investoren | sondereigentumsverwaltung | Sondereigentumsverwaltung für Kapitalanleger | /se-verwaltung/ | angebot |
| vermieter-investoren | betriebskostenabrechnung | Die Betriebskostenabrechnung für Vermieter | /mietverwaltung/ | angebot |
| vermieter-investoren | mieterauswahl | Mieterauswahl: sorgfältig, fair und datensparsam | /vermietung/ | kontakt |
| vermieter-investoren | leerstand-vermeiden | Leerstand: Ursachen erkennen, Vermietung planen | /vermietung/ | kontakt |
| mieter | schadensmeldung | Einen Schaden melden | /service/ | service |
| mieter | notfall-was-tun | Notfall im Haus: Was ist ein Notfall und was ist zu tun | /notfall/ | notfall |
| mieter | erreichbarkeit | Erreichbarkeit und Ansprechpartner | /service/ | service |
| mieter | nebenkostenabrechnung-mieter | Die Nebenkostenabrechnung verstehen | /service/ | service |
| mieter | eigentuemerportal | Das Portal für Eigentümer und Mieter | /service/ | service |
| kosten-vertrag | kosten-hausverwaltung | Was kostet eine Hausverwaltung? | /angebot/ | angebot |
| kosten-vertrag | leistungsumfang-verwaltervertrag | Was ist in der Verwaltung enthalten? | /weg-verwaltung/ | angebot |
| kosten-vertrag | vertragslaufzeit-verwaltervertrag | Vertragslaufzeit und Beendigung des Verwaltervertrags | /verwalterwechsel/ | angebot |

Hinweis „keine Aufnahmegebühr“: von der Geschäftsführung am 23.09.2026 bestätigt, als FAQ-Eintrag in `content/faq/kosten-vertrag.yaml`.

## 2. Format Artikel (`content/wissen/<slug>.md`)

```markdown
---
titel: "Verwalterwechsel in der WEG: Ablauf, Zeitplan, Unterlagen"
slug: verwalterwechsel-weg
zielgruppe: weg-beirat
beschreibung: "Sachliche Zusammenfassung in höchstens 160 Zeichen für Suchmaschinen und Karten."
stand: 2026-09-23
autor: "Hausverwaltung Müller GmbH"
freigabe: nein
leistung: /verwalterwechsel/
cta: angebot
faq:
  - frage: "Kurze, präzise Frage?"
    antwort: "Antwort in zwei bis vier Sätzen."
---

Einleitender Absatz ohne Überschrift.

## Erste Zwischenüberschrift

Text ...
```

Regeln:
- Pflichtfelder: `titel`, `slug` (identisch zum Dateinamen), `zielgruppe` (weg-beirat, vermieter-investoren, mieter, kosten-vertrag), `beschreibung`, `stand` (JJJJ-MM-TT), `autor`, `freigabe` (ja oder nein), `leistung`, `cta` (angebot, kontakt, service, notfall). `faq` ist optional (höchstens fünf Einträge, erzeugt FAQPage-Daten auf der Artikelseite).
- Markdown: nur H2 und H3, Absätze, Listen, Hervorhebungen, interne Links als relative Pfade (`/wissen/eigentuemerversammlung/`). Kein HTML, keine Bilder, keine Tabellen mit Zahlenwerten, die nicht belegt sind.
- Der Hinweis „keine Einzelfallberatung“ und das Stand-Datum werden vom Template ergänzt und stehen nicht im Markdown.
- Platzhalter in eckigen Klammern, z. B. `[Notfallnummer ergänzen]`.

## 3. Format FAQ (`content/faq/<zielgruppe>.yaml`)

```yaml
zielgruppe: weg-beirat
titel: "WEG und Beirat"
fragen:
  - frage: "Wie läuft ein Verwalterwechsel ab?"
    antwort: "Antwort in zwei bis fünf Sätzen. Einfaches Markdown für Hervorhebungen und Links ist erlaubt."
    artikel: verwalterwechsel-weg
    freigabe: nein
```

Zielgruppen-Titel: weg-beirat „WEG und Beirat“, vermieter-investoren „Vermieter und Investoren“, mieter „Mieter“, kosten-vertrag „Kosten und Vertrag“.
