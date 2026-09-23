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

## 4. Erweiterung (Stand 23.09.2026)

Neue Zielgruppe `verkauf-bewertung` (Titel „Verkauf und Bewertung“). FAQ-Datei `content/faq/verkauf-bewertung.yaml`.

| Zielgruppe | Slug | Arbeitstitel | `leistung` | `cta` |
|---|---|---|---|---|
| weg-beirat | verwaltungsbeirat-aufgaben | Der Verwaltungsbeirat: Aufgaben und Zusammenarbeit | /weg-verwaltung/ | angebot |
| weg-beirat | hausgeld | Das Hausgeld: Zusammensetzung und Zahlung | /weg-verwaltung/ | angebot |
| weg-beirat | sonderumlage | Die Sonderumlage | /weg-verwaltung/ | angebot |
| weg-beirat | bauliche-veraenderungen-weg | Bauliche Veränderungen in der WEG | /weg-verwaltung/ | angebot |
| weg-beirat | erhaltung-planen-weg | Erhaltung des Gemeinschaftseigentums planen | /weg-verwaltung/ | angebot |
| weg-beirat | teilungserklaerung-gemeinschaftsordnung | Teilungserklärung und Gemeinschaftsordnung | /weg-verwaltung/ | angebot |
| weg-beirat | verkehrssicherungspflicht | Verkehrssicherungspflichten in Wohnanlagen | /weg-verwaltung/ | angebot |
| weg-beirat | gebaeudeversicherung-weg | Versicherungen der Gemeinschaft | /weg-verwaltung/ | angebot |
| weg-beirat | ladestationen-e-mobilitaet | Ladestationen und E-Mobilität in der WEG | /weg-verwaltung/ | angebot |
| weg-beirat | online-eigentuemerversammlung | Online-Teilnahme und virtuelle Eigentümerversammlung | /weg-verwaltung/ | angebot |
| vermieter-investoren | asset-management-immobilien | Asset Management für Wohnimmobilien | /asset-management/ | kontakt |
| vermieter-investoren | property-asset-facility-management | Property, Asset und Facility Management: die Unterschiede | /asset-management/ | kontakt |
| vermieter-investoren | instandhaltungsplanung-bestand | Instandhaltungsplanung für Bestandsimmobilien | /mietverwaltung/ | angebot |
| vermieter-investoren | mieterhoehung-vergleichsmiete | Mieterhöhung bis zur ortsüblichen Vergleichsmiete | /mietverwaltung/ | angebot |
| vermieter-investoren | index-staffelmiete | Indexmiete und Staffelmiete | /mietverwaltung/ | angebot |
| vermieter-investoren | mietkaution | Die Mietkaution | /mietverwaltung/ | angebot |
| vermieter-investoren | wohnungsuebergabe | Wohnungsübergabe und Übergabeprotokoll | /vermietung/ | kontakt |
| vermieter-investoren | modernisierung-vermieter | Modernisierung im vermieteten Bestand | /mietverwaltung/ | angebot |
| vermieter-investoren | co2-kostenaufteilung | CO2-Kostenaufteilung zwischen Vermieter und Mieter | /mietverwaltung/ | angebot |
| vermieter-investoren | mietverwaltung-oder-se-verwaltung | Mietverwaltung oder SE-Verwaltung: was passt? | /se-verwaltung/ | angebot |
| verkauf-bewertung | immobilie-verkaufen-ablauf | Eine Immobilie verkaufen: der Ablauf | /verkauf/ | kontakt |
| verkauf-bewertung | unterlagen-immobilienverkauf | Unterlagen für den Immobilienverkauf | /verkauf/ | kontakt |
| verkauf-bewertung | energieausweis | Der Energieausweis bei Verkauf und Vermietung | /verkauf/ | kontakt |
| verkauf-bewertung | wertermittlung-verfahren | Verfahren der Wertermittlung | /wertgutachten/ | kontakt |
| verkauf-bewertung | vermietete-wohnung-verkaufen | Eine vermietete Wohnung verkaufen | /verkauf/ | kontakt |
| verkauf-bewertung | eigentumswohnung-kaufen-pruefen | Eigentumswohnung kaufen: Unterlagen der WEG prüfen | /verkauf/ | kontakt |
| mieter | hausordnung | Die Hausordnung | /service/ | service |
| mieter | rauchwarnmelder | Rauchwarnmelder in der Wohnung | /service/ | service |
| mieter | schimmel-vermeiden | Feuchtigkeit und Schimmel vermeiden | /service/ | service |
| mieter | auszug-wohnungsrueckgabe | Auszug und Rückgabe der Wohnung | /service/ | service |
| kosten-vertrag | verwaltervertrag-inhalte | Der Verwaltervertrag: typische Inhalte | /weg-verwaltung/ | angebot |
| kosten-vertrag | sonderleistungen-verwaltung | Grundleistungen und Sonderleistungen der Verwaltung | /weg-verwaltung/ | angebot |

Leistungsseite `/asset-management/`: Entwurf, Leistung durch die Geschäftsführung zu bestätigen (`[Leistung Asset Management bestätigen]`).
