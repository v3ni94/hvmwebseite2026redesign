<?php

declare(strict_types=1);

namespace Hvm\Validation;

/**
 * Prüfung einer hochgeladenen Datei ($_FILES-Eintrag): Fehlercode, Größe, tatsächlicher Inhaltstyp
 * (finfo, nicht der vom Client gesendete Content-Type) und Dateiendung. Ohne Abhängigkeiten (keine
 * Datenbank), damit die Formularseite auch prüfen kann, wenn Hvm\Service\ApplicationService wegen
 * einer fehlenden Datenbankverbindung nicht zur Verfügung steht.
 */
final class UploadValidator
{
    /**
     * @param array<string, mixed>|null $file
     * @return array{ok: bool, fehler: ?string, tmp_name: ?string, groesse: int, mime: ?string}
     */
    public static function validate(?array $file, string $allowedMime, string $extension, int $maxBytes): array
    {
        if ($file === null || !isset($file['error'])) {
            return self::ablehnen(sprintf('Bitte fügen Sie Ihre Bewerbungsunterlagen als %s-Datei bei.', strtoupper($extension)));
        }
        $error = (int) $file['error'];
        if ($error === UPLOAD_ERR_NO_FILE) {
            return self::ablehnen(sprintf('Bitte fügen Sie Ihre Bewerbungsunterlagen als %s-Datei bei.', strtoupper($extension)));
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return self::ablehnen(sprintf('Die Datei ist zu groß. Erlaubt sind höchstens %d MB.', intdiv($maxBytes, 1024 * 1024)));
        }
        if ($error !== UPLOAD_ERR_OK) {
            return self::ablehnen('Die Datei konnte nicht hochgeladen werden. Bitte versuchen Sie es erneut.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        // is_uploaded_file() ist bei echten HTTP-Anfragen Pflicht (verhindert Path-Traversal über eine
        // frei gewählte tmp_name). Unter der PHP-CLI (Tests, bin/-Skripte) gibt es keinen echten
        // Upload-Mechanismus, is_uploaded_file() wäre dort immer false; deshalb genügt dort eine
        // einfache Lesbarkeitsprüfung.
        $istEchterUpload = PHP_SAPI !== 'cli' ? is_uploaded_file($tmpName) : is_file($tmpName) && is_readable($tmpName);
        if ($tmpName === '' || !$istEchterUpload) {
            return self::ablehnen('Die Datei konnte nicht hochgeladen werden. Bitte versuchen Sie es erneut.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return self::ablehnen('Die Datei ist leer.');
        }
        if ($size > $maxBytes) {
            return self::ablehnen(sprintf('Die Datei ist zu groß. Erlaubt sind höchstens %d MB.', intdiv($maxBytes, 1024 * 1024)));
        }

        $originalName = (string) ($file['name'] ?? '');
        if (!preg_match('/\.' . preg_quote($extension, '/') . '$/i', $originalName)) {
            return self::ablehnen(sprintf('Bitte laden Sie eine Datei mit der Endung .%s hoch.', $extension));
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpName) ?: null;
        if ($mime !== $allowedMime) {
            return self::ablehnen(sprintf('Die Datei ist keine gültige %s-Datei.', strtoupper($extension)));
        }

        return ['ok' => true, 'fehler' => null, 'tmp_name' => $tmpName, 'groesse' => $size, 'mime' => $mime];
    }

    /**
     * @return array{ok: bool, fehler: ?string, tmp_name: ?string, groesse: int, mime: ?string}
     */
    private static function ablehnen(string $grund): array
    {
        return ['ok' => false, 'fehler' => $grund, 'tmp_name' => null, 'groesse' => 0, 'mime' => null];
    }
}
