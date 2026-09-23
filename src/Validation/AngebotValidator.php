<?php

declare(strict_types=1);

namespace Hvm\Validation;

use DateTimeImmutable;

/**
 * Regeln des Angebotsformulars (MP 6.1, Datensparsamkeit MP 9).
 *
 * Pflicht: Verwaltungsart, Objekt-PLZ und Ort, mindestens eine Wohn- oder Gewerbeeinheit,
 * Vor- oder Nachname, E-Mail-Adresse, Kenntnisnahme des Datenschutzhinweises. Alles andere ist optional.
 */
final class AngebotValidator
{
    public const ARTEN = [
        'weg' => 'WEG-Verwaltung',
        'miet' => 'Mietverwaltung',
        'se' => 'SE-Verwaltung',
    ];

    public const ANREDEN = [
        'frau' => 'Frau',
        'herr' => 'Herr',
        'keine' => 'Keine Angabe',
    ];

    public const ROLLEN = [
        'eigentuemer' => 'Eigentümer',
        'beirat' => 'Beirat',
        'investor' => 'Investor',
        'sonstige' => 'Sonstige',
    ];

    /** Höchstlängen, identisch mit maxlength im Formular und den Spalten in migrations/0003_leads.sql */
    public const MAX = [
        'strasse' => 150,
        'ort' => 100,
        'vorname' => 100,
        'nachname' => 100,
        'nachricht' => 3000,
    ];

    public const EINHEITEN_MAX = 9999;
    public const BAUJAHR_MIN = 1800;

    /**
     * Reihenfolge der Felder im Formular (für die Fehlerzusammenfassung) und Zuordnung zu den Schritten 1 bis 5.
     */
    public const FELDER = [
        'art' => 1,
        'strasse' => 2, 'plz' => 2, 'ort' => 2, 'baujahr' => 2,
        'wohneinheiten' => 2, 'gewerbeeinheiten' => 2, 'stellplaetze' => 2,
        'beginn' => 3, 'aktueller_verwalter' => 3,
        'anrede' => 4, 'vorname' => 4, 'nachname' => 4, 'email' => 4, 'telefon' => 4, 'rolle' => 4, 'nachricht' => 4,
        'datenschutz' => 5,
    ];

    /** Felder als Radiogruppe: der Sprunglink zeigt auf die erste Option. */
    public const RADIOGRUPPEN = ['art' => 'weg', 'aktueller_verwalter' => 'ja'];

    /**
     * @param array<string, mixed> $input
     * @return array{valid: bool, errors: array<string, string>, form: array<string, mixed>, data: array<string, mixed>}
     */
    public function validate(array $input, DateTimeImmutable $now): array
    {
        $v = new Validator($input);
        $year = (int) $now->format('Y');

        $art = $v->choice('art', 'Verwaltungsart', array_keys(self::ARTEN), true, 'Bitte wählen Sie die Verwaltungsart.');

        $strasse = $v->text('strasse', 'Straße und Hausnummer', self::MAX['strasse']);
        $plz = $v->plz('plz', 'PLZ', true, 'Bitte geben Sie die Postleitzahl des Objekts an.');
        $ort = $v->text('ort', 'Ort', self::MAX['ort'], true, 'Bitte geben Sie den Ort des Objekts an.');
        $baujahr = $v->integer('baujahr', 'Baujahr', self::BAUJAHR_MIN, $year + 5);
        $wohn = $v->integer('wohneinheiten', 'Wohneinheiten', 0, self::EINHEITEN_MAX, false, 0);
        $gewerbe = $v->integer('gewerbeeinheiten', 'Gewerbeeinheiten', 0, self::EINHEITEN_MAX, false, 0);
        $stell = $v->integer('stellplaetze', 'Stellplätze', 0, self::EINHEITEN_MAX, false, 0);
        if (!$v->hasError('wohneinheiten') && !$v->hasError('gewerbeeinheiten') && (int) $wohn + (int) $gewerbe < 1) {
            $v->addError('wohneinheiten', 'Bitte geben Sie mindestens eine Wohn- oder Gewerbeeinheit an.');
        }

        $firstOfMonth = $now->modify('first day of this month')->setTime(0, 0);
        $beginn = $v->month('beginn', 'Gewünschter Verwaltungsbeginn', $firstOfMonth->modify('-12 months'), $firstOfMonth->modify('+60 months'));
        $verwalter = $v->yesNo('aktueller_verwalter', 'Aktueller Verwalter');

        $anrede = $v->choice('anrede', 'Anrede', array_keys(self::ANREDEN));
        $vorname = $v->text('vorname', 'Vorname', self::MAX['vorname']);
        $nachname = $v->text('nachname', 'Nachname', self::MAX['nachname']);
        if ($vorname === null && $nachname === null && !$v->hasError('vorname') && !$v->hasError('nachname')) {
            $v->addError('nachname', 'Bitte geben Sie Ihren Vor- oder Nachnamen an.');
        }
        $email = $v->email('email', 'E-Mail-Adresse', true);
        $telefon = $v->phone('telefon');
        $rolle = $v->choice('rolle', 'Ihre Rolle', array_keys(self::ROLLEN));
        $nachricht = $v->multiline('nachricht', 'Nachricht', self::MAX['nachricht']);

        $v->accepted('datenschutz', 'Bitte bestätigen Sie, dass Sie den Datenschutzhinweis gelesen haben.');

        $form = $v->values();
        $errors = self::ordered($v->errors());

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'form' => $form,
            'data' => [
                'management_form' => $art,
                'object_street' => $strasse,
                'object_zip' => $plz,
                'object_city' => $ort,
                'year_of_construction' => $baujahr,
                'units_residential' => (int) $wohn,
                'units_commercial' => (int) $gewerbe,
                'units_parking' => (int) $stell,
                'management_start' => $beginn,
                'has_current_manager' => $verwalter,
                'contact_salutation' => $anrede,
                'contact_first_name' => $vorname,
                'contact_last_name' => $nachname,
                'contact_email' => $email,
                'contact_phone' => $telefon,
                'contact_role' => $rolle,
                'message' => $nachricht,
            ],
        ];
    }

    /**
     * Sortiert Fehler in Formularreihenfolge.
     *
     * @param array<string, string> $errors
     * @return array<string, string>
     */
    public static function ordered(array $errors): array
    {
        $sorted = [];
        foreach (array_keys(self::FELDER) as $field) {
            if (isset($errors[$field])) {
                $sorted[$field] = $errors[$field];
            }
        }

        return $sorted + $errors;
    }

    /**
     * HTML-ID des Eingabeelements eines Feldes (für Sprunglinks der Fehlerzusammenfassung).
     */
    public static function fieldId(string $field): string
    {
        return 'feld-' . $field . (isset(self::RADIOGRUPPEN[$field]) ? '-' . self::RADIOGRUPPEN[$field] : '');
    }
}
