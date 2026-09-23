<?php

declare(strict_types=1);

namespace Hvm\View;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Hvm\Http\Middleware\Csrf;
use Hvm\Http\RequestContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig-Funktionen und Filter laut docs/architektur.md Abschnitt 6.
 */
final class TwigExtension extends AbstractExtension
{
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** @var array<string, string>|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly RequestContext $context,
        private readonly ?Csrf $csrf,
        private readonly string $manifestPath,
        private readonly bool $production = true,
    ) {
    }

    public function getFunctions(): array
    {
        $html = ['is_safe' => ['html']];

        return [
            new TwigFunction('asset', $this->asset(...)),
            new TwigFunction('csp_nonce', $this->cspNonce(...)),
            new TwigFunction('csrf_field', $this->csrfField(...), $html),
            new TwigFunction('csrf_token', $this->csrfToken(...)),
            new TwigFunction('placeholder', self::placeholder(...), $html),
            new TwigFunction('datum', self::datum(...)),
            new TwigFunction('betrag', self::betrag(...)),
            new TwigFunction('tel_href', self::telHref(...)),
            new TwigFunction('json_ld', self::jsonLd(...), $html),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('datum', self::datum(...)),
            new TwigFilter('md_inline', self::mdInline(...), ['is_safe' => ['html']]),
            new TwigFilter('betrag', self::betrag(...)),
            new TwigFilter('tel_href', self::telHref(...)),
            new TwigFilter('json_ld', self::jsonLd(...), ['is_safe' => ['html']]),
            new TwigFilter('trennen', self::trennen(...)),
            new TwigFilter('trennen_html', self::trennenHtml(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Pfad zur gebauten Datei laut manifest.json, z. B. asset('app.css') => /assets/build/app.3f2a1b9c0d.css.
     * Ohne Manifest oder Eintrag: ungehashter Pfad (der Build muss laufen, composer build).
     */
    public function asset(string $name): string
    {
        $name = ltrim($name, '/');
        if ($this->manifest === null || !$this->production) {
            $this->manifest = $this->loadManifest();
        }

        return '/assets/build/' . ($this->manifest[$name] ?? $name);
    }

    /**
     * @return array<string, string>
     */
    private function loadManifest(): array
    {
        if (!is_file($this->manifestPath)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->manifestPath), true);

        return is_array($data) ? array_filter($data, 'is_string') : [];
    }

    public function cspNonce(): string
    {
        return $this->context->nonce();
    }

    public function csrfToken(): string
    {
        if ($this->csrf === null) {
            throw new \LogicException('CSRF-Dienst ist nicht verfügbar.');
        }

        return $this->csrf->token();
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="' . Csrf::FIELD . '" value="' . self::e($this->csrfToken()) . '">';
    }

    /**
     * Sichtbarer Platzhalter für fehlende Angaben, z. B. placeholder('Telefonnummer bestätigen').
     */
    public static function placeholder(string $text): string
    {
        return '<span class="placeholder">[' . self::e($text) . ']</span>';
    }

    /**
     * Datum als TT.MM.JJJJ. Akzeptiert DateTimeInterface, Zeitstempel oder Datumszeichenkette.
     */
    public static function datum(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $berlin = new DateTimeZone('Europe/Berlin');
        if ($value instanceof DateTimeInterface) {
            $date = DateTimeImmutable::createFromInterface($value);
        } elseif (is_int($value)) {
            $date = (new DateTimeImmutable('@' . $value))->setTimezone($berlin);
        } elseif (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            // Reines Datum ohne Uhrzeit: keine Zeitzonenumrechnung
            return DateTimeImmutable::createFromFormat('!Y-m-d', $value, $berlin)?->format('d.m.Y') ?? '';
        } elseif (is_string($value)) {
            try {
                $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            } catch (\Exception) {
                return '';
            }
        } else {
            return '';
        }

        return $date->setTimezone($berlin)->format('d.m.Y');
    }

    /**
     * Betrag als 1.234,56 EUR.
     */
    public static function betrag(mixed $value): string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '';
        }

        return number_format((float) $value, 2, ',', '.') . ' EUR';
    }

    /**
     * tel:-Link aus einer Rufnummer in beliebiger Schreibweise, national in +49 umgesetzt.
     * Leere Eingabe (null) ergibt eine leere Zeichenkette.
     */
    public static function telHref(?string $number): string
    {
        if ($number === null || trim($number) === '') {
            return '';
        }
        $clean = str_replace('(0)', '', $number);
        $plus = str_starts_with(ltrim($clean), '+');
        $digits = (string) preg_replace('/\D+/', '', $clean);
        if ($digits === '') {
            return '';
        }
        if ($plus) {
            return 'tel:+' . $digits;
        }
        if (str_starts_with($digits, '00')) {
            return 'tel:+' . substr($digits, 2);
        }
        if (str_starts_with($digits, '0')) {
            return 'tel:+49' . substr($digits, 1);
        }

        return 'tel:' . $digits;
    }

    /**
     * JSON für <script type="application/ld+json">. <, >, &, ' und " werden als \u00XX kodiert,
     * dadurch ist ein Ausbruch aus dem Script-Element ausgeschlossen.
     */
    public static function jsonLd(mixed $data): string
    {
        return json_encode($data, self::JSON_FLAGS);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Einfaches Markdown (Links, Hervorhebungen) für kurze Texte wie FAQ-Antworten.
     * HTML-Eingaben werden escaped, unsichere Links verworfen, äußere Absätze entfernt.
     */
    public static function mdInline(string $markdown): string
    {
        static $converter = null;
        $converter ??= new \League\CommonMark\CommonMarkConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $html = trim((string) $converter->convert($markdown));

        return (string) preg_replace('#^<p>(.*)</p>$#s', '$1', $html);
    }

    /**
     * Wortbestandteile, vor denen in langen Komposita ein weiches Trennzeichen (U+00AD) stehen darf.
     * Ein senkrechter Strich legt die Trennstelle innerhalb des Bestandteils fest (mitglied|schaft).
     * Hintergrund: hyphens: auto hängt vom Trennwörterbuch des Browsers ab und fehlt in manchen
     * Umgebungen. Große Überschriften brechen dann mitten im Wort. Das weiche Trennzeichen ist
     * unsichtbar und erscheint nur als Trennstrich, wenn die Zeile tatsächlich dort umbricht.
     */
    private const FUGEN = [
        'gemeinschaft', 'eigentümer', 'eigentum', 'verwaltung', 'verwalter', 'versammlung', 'abrechnung',
        'aufnahme', 'versicherung', 'haftpflicht', 'beilegung', 'streit', 'beauftragte', 'schutz',
        'erklärung', 'verfahren', 'setzung', 'kosten', 'einheiten', 'wechsel', 'vertrag', 'laufzeit',
        'gebiete', 'rücklage', 'beschluss', 'sammlung', 'meldung', 'umfang', 'bestellung', 'abberufung',
        'übergabe', 'portal', 'objekte', 'wohnung', 'gutachten', 'vorbereitung', 'fassung', 'information',
        'aufstellung', 'auseinander', 'übersicht', 'mitglied|schaft', 'fach|verbänd',
    ];

    /** Mindestlänge eines Wortes, ab der getrennt wird, und Mindestlänge jedes Teils. */
    private const TRENN_MIN_WORT = 13;
    private const TRENN_MIN_TEIL = 4;

    /**
     * Setzt weiche Trennzeichen an Fugen langer deutscher Komposita (Klartext, Ausgabe wird escaped).
     * Beispiel: Eigentümergemeinschaft => Eigentümer\u{AD}gemeinschaft.
     */
    public static function trennen(?string $text): string
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }

        return (string) preg_replace_callback('/\p{L}{' . self::TRENN_MIN_WORT . ',}/u', static function (array $m): string {
            $wort = $m[0];
            $klein = mb_strtolower($wort);
            $laenge = mb_strlen($wort);
            $stellen = [];
            foreach (self::FUGEN as $fuge) {
                $marke = mb_strpos($fuge, '|');
                $versatz = $marke === false ? 0 : $marke;
                $suche = str_replace('|', '', $fuge);
                $offset = 0;
                while (($pos = mb_strpos($klein, $suche, $offset)) !== false) {
                    $stelle = $pos + $versatz;
                    if ($stelle >= self::TRENN_MIN_TEIL && $laenge - $stelle >= self::TRENN_MIN_TEIL) {
                        $stellen[$stelle] = true;
                    }
                    $offset = $pos + 1;
                }
            }
            if ($stellen === []) {
                return $wort;
            }
            ksort($stellen);
            $ergebnis = '';
            $letzte = 0;
            foreach (array_keys($stellen) as $pos) {
                if ($pos - $letzte < self::TRENN_MIN_TEIL) {
                    continue;
                }
                $ergebnis .= mb_substr($wort, $letzte, $pos - $letzte) . "\u{AD}";
                $letzte = $pos;
            }

            return $ergebnis . mb_substr($wort, $letzte);
        }, $text);
    }

    /**
     * Wie trennen(), aber für fertiges HTML: nur der Text in Überschriften (h1 bis h4) wird bearbeitet,
     * Tags und Attribute bleiben unverändert.
     */
    public static function trennenHtml(?string $html): string
    {
        $html = (string) $html;

        return (string) preg_replace_callback('#(<h[1-4]\b[^>]*>)(.*?)(</h[1-4]>)#su', static function (array $m): string {
            $inhalt = preg_replace_callback('/>([^<]+)</u', static fn (array $t): string => '>' . self::trennen($t[1]) . '<', '>' . $m[2] . '<');

            return $m[1] . substr((string) $inhalt, 1, -1) . $m[3];
        }, $html);
    }
}
