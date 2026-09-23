<?php

declare(strict_types=1);

namespace Hvm\Validation;

use DateTimeImmutable;

/**
 * Serverseitige Validierung mit deutschen Fehlermeldungen.
 *
 * Jede Regel liest ein Feld aus der Eingabe, normalisiert es (Trim, Steuerzeichen entfernen)
 * und legt den bereinigten Wert unter values() ab. Je Feld wird nur der erste Fehler gemeldet.
 *
 *   $v = new Validator($request->post());
 *   $v->text('ort', 'Ort', max: 100, required: true);
 *   $v->email('email', required: true);
 *   if (!$v->isValid()) { $v->errors(); }
 *
 * Meldungen können je Regel über $message überschrieben werden.
 */
final class Validator
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $input z. B. $request->post()
     */
    public function __construct(private readonly array $input)
    {
    }

    /**
     * Einzeiliger Text. Zeilenumbrüche und Steuerzeichen werden entfernt, Leerraum zusammengefasst.
     */
    public function text(string $field, string $label, int $max = 255, bool $required = false, ?string $message = null, int $min = 0): ?string
    {
        $raw = $this->raw($field);
        if ($raw === false) {
            return $this->fail($field, $message ?? sprintf('Die Eingabe im Feld „%s“ ist ungültig.', $label));
        }
        $value = self::singleLine($raw);
        if ($value === '') {
            $this->values[$field] = null;
            if ($required) {
                $this->fail($field, $message ?? sprintf('Bitte füllen Sie das Feld „%s“ aus.', $label));
            }

            return null;
        }
        $this->values[$field] = $value;
        if (mb_strlen($value) > $max) {
            return $this->fail($field, sprintf('Bitte kürzen Sie die Eingabe im Feld „%s“ auf höchstens %d Zeichen.', $label, $max));
        }
        if (mb_strlen($value) < $min) {
            return $this->fail($field, sprintf('Bitte geben Sie im Feld „%s“ mindestens %d Zeichen ein.', $label, $min));
        }

        return $value;
    }

    /**
     * Mehrzeiliger Text (Freitext). Zeilenumbrüche bleiben erhalten, übrige Steuerzeichen werden entfernt.
     */
    public function multiline(string $field, string $label, int $max = 3000, bool $required = false, ?string $message = null): ?string
    {
        $raw = $this->raw($field);
        if ($raw === false) {
            return $this->fail($field, $message ?? sprintf('Die Eingabe im Feld „%s“ ist ungültig.', $label));
        }
        $value = str_replace(["\r\n", "\r"], "\n", $raw);
        $value = (string) preg_replace('/[^\P{C}\n\t]/u', '', $value);
        $value = (string) preg_replace("/\n{3,}/", "\n\n", $value);
        $value = trim($value);
        if ($value === '') {
            $this->values[$field] = null;
            if ($required) {
                $this->fail($field, $message ?? sprintf('Bitte füllen Sie das Feld „%s“ aus.', $label));
            }

            return null;
        }
        $this->values[$field] = $value;
        if (mb_strlen($value) > $max) {
            return $this->fail($field, sprintf('Bitte kürzen Sie Ihre Nachricht auf höchstens %d Zeichen (derzeit %d).', $max, mb_strlen($value)));
        }

        return $value;
    }

    public function email(string $field, string $label = 'E-Mail-Adresse', bool $required = true, ?string $message = null): ?string
    {
        $value = $this->text($field, $label, 254, $required, $required ? ($message ?? 'Bitte geben Sie Ihre E-Mail-Adresse an.') : null);
        if ($value === null || $this->hasError($field)) {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return $this->fail($field, 'Bitte geben Sie eine gültige E-Mail-Adresse an, zum Beispiel name@example.org.');
        }

        return $value;
    }

    /**
     * Deutsche Postleitzahl, genau fünf Ziffern.
     */
    public function plz(string $field, string $label = 'PLZ', bool $required = true, ?string $message = null): ?string
    {
        $value = $this->text($field, $label, 10, $required, $message ?? 'Bitte geben Sie die Postleitzahl an.');
        if ($value === null || $this->hasError($field)) {
            return null;
        }
        $value = str_replace(' ', '', $value);
        $this->values[$field] = $value;
        if (!preg_match('/^\d{5}$/', $value)) {
            return $this->fail($field, 'Bitte geben Sie eine fünfstellige Postleitzahl an.');
        }

        return $value;
    }

    /**
     * Telefonnummer: Ziffern, Leerzeichen und + / ( ) - erlaubt, mindestens sechs Ziffern.
     */
    public function phone(string $field, string $label = 'Telefon', bool $required = false, ?string $message = null): ?string
    {
        $value = $this->text($field, $label, 30, $required, $message);
        if ($value === null || $this->hasError($field)) {
            return null;
        }
        if (!preg_match('#^\+?[0-9 ()/\-]+$#', $value) || preg_match_all('/\d/', $value) < 6) {
            return $this->fail($field, 'Bitte geben Sie eine gültige Telefonnummer an, zum Beispiel 0211 123456.');
        }

        return $value;
    }

    /**
     * Ganze Zahl im Bereich. Leere Eingabe ergibt $default (bzw. einen Fehler, wenn Pflicht).
     */
    public function integer(string $field, string $label, int $min, int $max, bool $required = false, ?int $default = null, ?string $message = null): ?int
    {
        $raw = $this->raw($field);
        $value = $raw === false ? null : trim($raw);
        if ($value === null || $value === '') {
            $this->values[$field] = $default;
            if ($required) {
                $this->fail($field, $message ?? sprintf('Bitte füllen Sie das Feld „%s“ aus.', $label));
            }

            return $default;
        }
        if (!preg_match('/^\d{1,9}$/', $value)) {
            $this->values[$field] = $value;
            $this->fail($field, sprintf('Bitte geben Sie im Feld „%s“ eine ganze Zahl ohne Nachkommastellen an.', $label));

            return null;
        }
        $number = (int) $value;
        $this->values[$field] = $number;
        if ($number < $min || $number > $max) {
            $this->fail($field, sprintf('Bitte geben Sie im Feld „%s“ einen Wert zwischen %d und %d an.', $label, $min, $max));

            return null;
        }

        return $number;
    }

    /**
     * Wert aus einer festen Liste (Radio, Select).
     *
     * @param list<string> $allowed
     */
    public function choice(string $field, string $label, array $allowed, bool $required = false, ?string $message = null): ?string
    {
        $raw = $this->raw($field);
        $value = $raw === false ? '' : trim($raw);
        if ($value === '') {
            $this->values[$field] = null;
            if ($required) {
                $this->fail($field, $message ?? sprintf('Bitte wählen Sie eine Angabe im Feld „%s“.', $label));
            }

            return null;
        }
        if (!in_array($value, $allowed, true)) {
            $this->values[$field] = null;
            $this->fail($field, sprintf('Bitte wählen Sie eine der angebotenen Möglichkeiten im Feld „%s“.', $label));

            return null;
        }
        $this->values[$field] = $value;

        return $value;
    }

    /**
     * Monat als JJJJ-MM (input type=month) oder MM.JJJJ (Texteingabe ohne Unterstützung für type=month).
     * Liefert den Monatsersten als JJJJ-MM-01.
     */
    public function month(string $field, string $label, DateTimeImmutable $earliest, DateTimeImmutable $latest, bool $required = false): ?string
    {
        $raw = $this->raw($field);
        $value = $raw === false ? '' : self::singleLine($raw);
        if ($value === '') {
            $this->values[$field] = null;
            if ($required) {
                $this->fail($field, sprintf('Bitte füllen Sie das Feld „%s“ aus.', $label));
            }

            return null;
        }
        $this->values[$field] = $value;
        $year = $month = null;
        if (preg_match('/^(\d{4})-(\d{1,2})(?:-\d{1,2})?$/', $value, $m)) {
            [$year, $month] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/^(?:\d{1,2}\.)?(\d{1,2})[.\/](\d{4})$/', $value, $m)) {
            [$year, $month] = [(int) $m[2], (int) $m[1]];
        }
        if ($year === null || $month < 1 || $month > 12) {
            return $this->fail($field, sprintf('Bitte geben Sie im Feld „%s“ Monat und Jahr an, zum Beispiel 01.2027.', $label));
        }
        $normalized = sprintf('%04d-%02d', $year, $month);
        $this->values[$field] = $normalized;
        if ($normalized < $earliest->format('Y-m') || $normalized > $latest->format('Y-m')) {
            return $this->fail($field, sprintf(
                'Bitte wählen Sie im Feld „%s“ einen Monat zwischen %s und %s.',
                $label,
                $earliest->format('m.Y'),
                $latest->format('m.Y')
            ));
        }

        return $normalized . '-01';
    }

    /**
     * Ja/Nein-Auswahl mit den Werten "ja" und "nein". Leer ergibt null.
     */
    public function yesNo(string $field, string $label, bool $required = false): ?bool
    {
        $value = $this->choice($field, $label, ['ja', 'nein'], $required);

        return $value === null ? null : $value === 'ja';
    }

    /**
     * Checkbox, die gesetzt sein muss (z. B. Kenntnisnahme des Datenschutzhinweises).
     */
    public function accepted(string $field, string $message): bool
    {
        $raw = $this->raw($field);
        $ok = is_string($raw) && in_array(trim($raw), ['1', 'on', 'ja', 'true'], true);
        $this->values[$field] = $ok;
        if (!$ok) {
            $this->fail($field, $message);
        }

        return $ok;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, string> Feldname => Meldung, in Reihenfolge der Prüfung
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, mixed> bereinigte Werte (auch bei Fehlern, zum erneuten Befüllen des Formulars)
     */
    public function values(): array
    {
        return $this->values;
    }

    public function value(string $field): mixed
    {
        return $this->values[$field] ?? null;
    }

    /**
     * Rohwert als Zeichenkette, '' wenn nicht vorhanden, false bei Arrays oder ungültigem UTF-8.
     */
    private function raw(string $field): string|false
    {
        $value = $this->input[$field] ?? '';
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return false;
        }

        return $value;
    }

    private function fail(string $field, string $message): null
    {
        $this->addError($field, $message);

        return null;
    }

    /**
     * Entfernt Steuer- und unsichtbare Formatzeichen, fasst Leerraum zusammen.
     */
    public static function singleLine(string $value): string
    {
        $value = (string) preg_replace('/\p{C}+/u', ' ', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }
}
