<?php

declare(strict_types=1);

namespace Hvm\Validation;

/**
 * Regeln des Bewerbungsformulars (MP 6.3, Karriere). Die Datei selbst wird von
 * Hvm\Service\ApplicationService geprüft (Typ, Größe), nicht hier.
 *
 * Pflicht: Name, E-Mail-Adresse, Einwilligungshinweis. Stelle, Telefon und Nachricht sind optional,
 * die Datei ist im Formular Pflicht, wird aber getrennt geprüft (unterschiedliche Fehlerquelle).
 */
final class BewerbungValidator
{
    public const MAX = [
        'stelle' => 150,
        'name' => 150,
        'nachricht' => 3000,
    ];

    /** Reihenfolge der Felder für die Fehlerzusammenfassung. */
    public const FELDER = ['stelle', 'name', 'email', 'telefon', 'nachricht', 'datei', 'einwilligung'];

    /**
     * @param array<string, mixed> $input
     * @param list<array{slug: string, titel: string}> $stellen
     * @return array{valid: bool, errors: array<string, string>, form: array<string, mixed>, data: array<string, mixed>}
     */
    public function validate(array $input, array $stellen = []): array
    {
        $v = new Validator($input);

        $stelle = $this->stelle($v, $stellen);
        $name = $v->text('name', 'Name', self::MAX['name'], true, 'Bitte geben Sie Ihren Namen an.');
        $email = $v->email('email', 'E-Mail-Adresse', true);
        $telefon = $v->phone('telefon');
        $nachricht = $v->multiline('nachricht', 'Nachricht', self::MAX['nachricht']);
        $v->accepted('einwilligung', 'Bitte bestätigen Sie den Hinweis zur Verarbeitung Ihrer Bewerbungsdaten.');

        $form = $v->values();
        $errors = self::ordered($v->errors());

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'form' => $form,
            'data' => [
                'stelle' => $stelle,
                'name' => $name,
                'email' => $email,
                'telefon' => $telefon,
                'nachricht' => $nachricht,
            ],
        ];
    }

    /**
     * Stelle: Auswahl aus config/stellen.php, wenn dort Einträge vorhanden sind, sonst Freitext
     * (Initiativbewerbung), in beiden Fällen optional.
     *
     * @param list<array{slug: string, titel: string}> $stellen
     */
    private function stelle(Validator $v, array $stellen): ?string
    {
        if ($stellen === []) {
            return $v->text('stelle', 'Gewünschte Stelle', self::MAX['stelle']);
        }
        $slugs = array_map(static fn (array $s): string => (string) $s['slug'], $stellen);

        return $v->choice('stelle', 'Gewünschte Stelle', $slugs);
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
