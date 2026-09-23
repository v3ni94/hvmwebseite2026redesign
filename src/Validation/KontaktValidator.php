<?php

declare(strict_types=1);

namespace Hvm\Validation;

/**
 * Regeln des allgemeinen Kontaktformulars (MP 6.3, Datensparsamkeit MP 9).
 *
 * Pflicht: Anliegen, Name, E-Mail-Adresse, Nachricht, Kenntnisnahme des Datenschutzhinweises.
 * Telefon ist optional.
 */
final class KontaktValidator
{
    public const ANLIEGEN = [
        'allgemein' => 'Allgemeine Anfrage',
        'vermietung' => 'Vermietung',
        'verkauf' => 'Verkauf',
        'gutachten' => 'Wertgutachten',
        'verwaltung' => 'Verwaltung anfragen',
        'service' => 'Service für Mieter und Eigentümer',
    ];

    public const MAX = [
        'name' => 150,
        'nachricht' => 3000,
    ];

    /** Reihenfolge der Felder für die Fehlerzusammenfassung. */
    public const FELDER = ['anliegen', 'name', 'email', 'telefon', 'nachricht', 'datenschutz'];

    /**
     * @param array<string, mixed> $input
     * @return array{valid: bool, errors: array<string, string>, form: array<string, mixed>, data: array<string, mixed>}
     */
    public function validate(array $input): array
    {
        $v = new Validator($input);

        $anliegen = $v->choice('anliegen', 'Anliegen', array_keys(self::ANLIEGEN), true, 'Bitte wählen Sie Ihr Anliegen.');
        $name = $v->text('name', 'Name', self::MAX['name']);
        $email = $v->email('email', 'E-Mail-Adresse', true);
        $telefon = $v->phone('telefon');
        $nachricht = $v->multiline('nachricht', 'Nachricht', self::MAX['nachricht'], true, 'Bitte geben Sie Ihre Nachricht ein.');
        $v->accepted('datenschutz', 'Bitte bestätigen Sie, dass Sie den Datenschutzhinweis gelesen haben.');

        $form = $v->values();
        $errors = self::ordered($v->errors());

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'form' => $form,
            'data' => [
                'anliegen' => $anliegen,
                'name' => $name,
                'email' => $email,
                'telefon' => $telefon,
                'nachricht' => $nachricht,
            ],
        ];
    }

    /**
     * @param array<string, string> $errors
     * @return array<string, string>
     */
    public static function ordered(array $errors): array
    {
        $sorted = [];
        foreach (self::FELDER as $field) {
            if (isset($errors[$field])) {
                $sorted[$field] = $errors[$field];
            }
        }

        return $sorted + $errors;
    }

    public static function fieldId(string $field): string
    {
        return 'feld-' . $field;
    }
}
