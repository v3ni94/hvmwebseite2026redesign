<?php

declare(strict_types=1);

namespace Hvm\Service;

/**
 * Kanalzuordnung ohne Endgerätespeicherung (keine Cookies, kein Web Storage, TDDDG).
 *
 * Die Angaben reisen ausschließlich in der URL: CTA-Links reichen utm_*, gclid, den Landing-Pfad (lp)
 * und den Host eines fremden Referrers (ref) an das Angebotsformular weiter (cta_url() in Twig).
 * Das Formular übernimmt sie als versteckte Felder.
 *
 * Referrer werden auf Host und Pfad gekürzt, Query und Fragment entfallen (können personenbezogene Daten enthalten).
 */
final class Attribution
{
    public const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /** Weitergereichte Parameter außer utm_*: Google-Ads-Klick-ID, Landing-Pfad, Referrer-Host */
    public const PARAM_GCLID = 'gclid';
    public const PARAM_LANDING = 'lp';
    public const PARAM_REFERRER = 'ref';

    public const SOURCE_GOOGLE_ADS = 'google_ads';
    public const SOURCE_ORGANIC = 'organisch';
    public const SOURCE_REFERRAL = 'verweis';
    public const SOURCE_DIRECT = 'direkt';

    private const UTM_MAX = 150;
    private const PATH_MAX = 255;

    /** Hosts von Suchmaschinen (Suffixvergleich über regulären Ausdruck) */
    private const SEARCH_ENGINES = '/(^|\.)(google\.[a-z.]{2,6}|bing\.com|duckduckgo\.com|search\.yahoo\.com|yahoo\.com|ecosia\.org|qwant\.com|startpage\.com|yandex\.[a-z]{2,3}|baidu\.com|search\.brave\.com|suche\.t-online\.de|suche\.web\.de|suche\.gmx\.(net|de)|metager\.(de|org)|ask\.com)$/i';

    /**
     * utm_* aus einer Query, bereinigt und gekürzt. Nicht vorhandene Schlüssel fehlen im Ergebnis.
     *
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    public static function utm(array $query): array
    {
        $result = [];
        foreach (self::UTM_KEYS as $key) {
            $value = self::cleanText($query[$key] ?? null, self::UTM_MAX);
            if ($value !== null) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public static function gclid(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_\-]{10,200}$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * Interner Pfad ohne Query, z. B. "/weg-verwaltung/". Absolute URLs und Protokoll-relative Pfade werden verworfen.
     */
    public static function internalPath(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || !str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return null;
        }
        $path = explode('#', explode('?', $value, 2)[0], 2)[0];
        if (!preg_match('#^/[A-Za-z0-9/_\-.~%]*$#', $path) || strlen($path) > self::PATH_MAX) {
            return null;
        }

        return $path;
    }

    /**
     * Host eines Referrers (nur Host, Kleinbuchstaben). Eigener Host oder ungültige Angaben ergeben null.
     *
     * @param string|list<string> $ownHost eigene Hosts (APP_URL, Host-Header)
     */
    public static function referrerHost(mixed $referrer, string|array $ownHost): ?string
    {
        if (!is_string($referrer) || $referrer === '') {
            return null;
        }
        $host = str_contains($referrer, '://') ? parse_url($referrer, PHP_URL_HOST) : $referrer;
        if (!is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower(rtrim($host, '.'));
        if (!preg_match('/^[a-z0-9.\-]{1,253}$/', $host) || !str_contains($host, '.')) {
            return null;
        }
        if (self::sameSite($host, $ownHost)) {
            return null;
        }

        return $host;
    }

    /**
     * Referrer als "host/pfad" ohne Schema, Query und Fragment. Eigener Host ergibt null.
     *
     * @param string|list<string> $ownHost
     */
    public static function referrerForStorage(mixed $referrer, string|array $ownHost): ?string
    {
        $host = self::referrerHost($referrer, $ownHost);
        if ($host === null || !is_string($referrer)) {
            return null;
        }
        $path = str_contains($referrer, '://') ? parse_url($referrer, PHP_URL_PATH) : null;
        $path = is_string($path) && preg_match('#^/[A-Za-z0-9/_\-.~%]*$#', $path) ? $path : '';
        if (strlen($path) > 120) {
            $path = substr($path, 0, 120);
        }

        return substr($host . $path, 0, self::PATH_MAX);
    }

    /**
     * Kanal: utm_source, sonst google_ads bei gclid, sonst organisch bei Suchmaschinen-Referrer,
     * sonst verweis bei fremdem Referrer, sonst direkt.
     *
     * @param array<string, string> $utm
     */
    public static function deriveSource(array $utm, ?string $gclid, ?string $referrerHost): string
    {
        $utmSource = isset($utm['utm_source']) ? self::normalizeSource($utm['utm_source']) : null;
        if ($utmSource !== null) {
            return $utmSource;
        }
        if ($gclid !== null && $gclid !== '') {
            return self::SOURCE_GOOGLE_ADS;
        }
        if ($referrerHost !== null && $referrerHost !== '') {
            $host = strtolower(explode('/', $referrerHost, 2)[0]);

            return preg_match(self::SEARCH_ENGINES, $host) ? self::SOURCE_ORGANIC : self::SOURCE_REFERRAL;
        }

        return self::SOURCE_DIRECT;
    }

    public static function isSearchEngine(string $host): bool
    {
        return preg_match(self::SEARCH_ENGINES, strtolower($host)) === 1;
    }

    /**
     * Parameter, die ein CTA-Link aus der aktuellen Anfrage weiterreicht.
     *
     * @param array<string, mixed> $query       Query der aktuellen Seite
     * @param string               $currentPath Pfad der aktuellen Seite
     * @param ?string              $refererHeader Referer-Header der aktuellen Anfrage
     * @param string|list<string>  $ownHost     eigene Hosts
     * @return array<string, string>
     */
    public static function forwardParams(array $query, string $currentPath, ?string $refererHeader, string|array $ownHost): array
    {
        $params = self::utm($query);
        $gclid = self::gclid($query[self::PARAM_GCLID] ?? null);
        if ($gclid !== null) {
            $params[self::PARAM_GCLID] = $gclid;
        }
        $params[self::PARAM_LANDING] = self::internalPath($query[self::PARAM_LANDING] ?? null)
            ?? self::internalPath($currentPath)
            ?? '/';
        $ref = self::referrerHost($query[self::PARAM_REFERRER] ?? null, $ownHost)
            ?? self::referrerHost($refererHeader, $ownHost);
        if ($ref !== null) {
            $params[self::PARAM_REFERRER] = $ref;
        }

        return $params;
    }

    /**
     * Baut eine URL aus Pfad und Parametern. Leere Werte entfallen.
     *
     * @param array<string, mixed> $params
     */
    public static function buildUrl(string $path, array $params): string
    {
        $params = array_filter($params, static fn (mixed $v): bool => is_scalar($v) && (string) $v !== '');
        [$base, $existing] = array_pad(explode('?', $path, 2), 2, '');
        $merged = [];
        if ($existing !== '') {
            parse_str($existing, $merged);
        }
        $merged = array_merge($merged, $params);

        return $merged === [] ? $base : $base . '?' . http_build_query($merged, '', '&', PHP_QUERY_RFC3986);
    }

    private static function normalizeSource(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9_.\-]+/', '_', $value);
        $value = trim($value, '_');

        return $value === '' ? null : substr($value, 0, 50);
    }

    private static function cleanText(mixed $value, int $max): ?string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $value = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $value));
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    /**
     * @param string|list<string> $ownHosts
     */
    private static function sameSite(string $host, string|array $ownHosts): bool
    {
        $strip = static fn (string $h): string => str_starts_with($h, 'www.') ? substr($h, 4) : $h;
        foreach ((array) $ownHosts as $ownHost) {
            $own = strtolower(explode(':', (string) $ownHost, 2)[0]);
            if ($own !== '' && $strip($host) === $strip($own)) {
                return true;
            }
        }

        return false;
    }
}
