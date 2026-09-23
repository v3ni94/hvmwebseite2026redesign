<?php

declare(strict_types=1);

namespace Hvm\Security;

use Hvm\Support\Config;

/**
 * Spam- und Missbrauchsschutz für öffentliche Formulare (MP 6.6), ohne Dienste Dritter:
 *
 * - Honeypot: verstecktes Feld, das Menschen nicht sehen und nicht ausfüllen.
 * - Zeitfalle: signierter Zeitstempel (HMAC-SHA256 mit einem aus APP_KEY abgeleiteten Schlüssel).
 *   Absenden schneller als MIN_SECONDS gilt als Spam, älter als MAX_AGE als abgelaufen.
 * - Plausibilität: Links im Freitext begrenzt, keine Links in Namens- und Adressfeldern.
 *
 * Ergebnis: ['status' => 'ok'|'spam'|'abgelaufen', 'grund' => ?string]. Der Grund ist ein technischer
 * Schlüssel ohne personenbezogene Daten und darf protokolliert werden.
 */
final class SpamGuard
{
    public const HONEYPOT_FIELD = 'webseite';
    public const TOKEN_FIELD = '_zeit';
    public const MIN_SECONDS = 4;
    public const MAX_AGE = 86400;
    public const MAX_LINKS = 2;

    private const LINK_PATTERN = '~(?:https?://|www\.|\[url|<a\s)~i';

    private readonly string $key;

    private readonly bool $configured;

    public function __construct(Config $config)
    {
        $this->configured = self::hasAppKey($config);
        $this->key = self::deriveKey($config, 'spamguard-zeitfalle');
    }

    /**
     * Leitet einen zweckgebundenen Schlüssel aus APP_KEY ab (HMAC-SHA256, Zweck als Nachricht).
     * Ohne APP_KEY entsteht ein öffentlich bekannter Ersatzschlüssel: die Funktion bleibt erhalten,
     * der Schutz der Signatur aber nicht. Produktion setzt APP_KEY immer (docs/architektur.md Abschnitt 5).
     */
    public static function deriveKey(Config $config, string $purpose): string
    {
        $appKey = (string) $config->get('app.key', '');
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            $appKey = $decoded === false ? '' : $decoded;
        }
        if ($appKey === '') {
            $appKey = hash('sha256', 'hvm-ohne-app-key|' . (string) $config->get('app.url', ''), true);
        }

        return hash_hmac('sha256', $purpose, $appKey, true);
    }

    public static function hasAppKey(Config $config): bool
    {
        return trim((string) $config->get('app.key', '')) !== '';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /**
     * Signierter Zeitstempel für das versteckte Feld TOKEN_FIELD, Format "<unixzeit>.<hmac>".
     */
    public function issueToken(string $form, ?int $time = null): string
    {
        $time ??= time();

        return $time . '.' . $this->sign($form, $time);
    }

    /**
     * Ausstellungszeit eines gültig signierten Tokens, sonst null.
     */
    public function tokenTime(?string $token, string $form): ?int
    {
        if ($token === null || !preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $token, $m)) {
            return null;
        }
        $time = (int) $m[1];

        return hash_equals($this->sign($form, $time), $m[2]) ? $time : null;
    }

    /**
     * @param array<string, mixed> $post           Formulardaten
     * @param list<string>         $freitextFelder Felder, in denen bis zu MAX_LINKS Links erlaubt sind
     * @param list<string>         $ohneLinks      Felder, in denen Links nie vorkommen (Namen, Anschrift)
     * @return array{status: 'ok'|'spam'|'abgelaufen', grund: ?string}
     */
    public function check(array $post, string $form, array $freitextFelder = [], array $ohneLinks = [], ?int $now = null): array
    {
        $now ??= time();

        $honeypot = $post[self::HONEYPOT_FIELD] ?? '';
        if (!is_string($honeypot) || trim($honeypot) !== '') {
            return ['status' => 'spam', 'grund' => 'honeypot'];
        }

        $token = $post[self::TOKEN_FIELD] ?? null;
        $issued = $this->tokenTime(is_string($token) ? $token : null, $form);
        if ($issued === null) {
            return ['status' => 'spam', 'grund' => 'zeitfalle_signatur'];
        }
        $age = $now - $issued;
        if ($age < self::MIN_SECONDS) {
            return ['status' => 'spam', 'grund' => 'zeitfalle_schnell'];
        }
        if ($age > self::MAX_AGE) {
            return ['status' => 'abgelaufen', 'grund' => 'zeitfalle_abgelaufen'];
        }

        foreach ($ohneLinks as $field) {
            $value = $post[$field] ?? '';
            if (is_string($value) && self::countLinks($value) > 0) {
                return ['status' => 'spam', 'grund' => 'link_in_feld'];
            }
        }
        foreach ($freitextFelder as $field) {
            $value = $post[$field] ?? '';
            if (is_string($value) && self::countLinks($value) > self::MAX_LINKS) {
                return ['status' => 'spam', 'grund' => 'links_im_freitext'];
            }
        }

        return ['status' => 'ok', 'grund' => null];
    }

    public static function countLinks(string $text): int
    {
        return preg_match_all(self::LINK_PATTERN, $text);
    }

    private function sign(string $form, int $time): string
    {
        return hash_hmac('sha256', $form . '|' . $time, $this->key);
    }
}
