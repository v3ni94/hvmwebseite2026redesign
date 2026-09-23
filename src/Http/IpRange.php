<?php

declare(strict_types=1);

namespace Hvm\Http;

/**
 * Prüft IPv4- und IPv6-Adressen gegen CIDR-Bereiche.
 */
final class IpRange
{
    /**
     * @param list<string> $ranges
     */
    public static function matchesAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::matches($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $ip, string $range): bool
    {
        $range = trim($range);
        if ($range === '') {
            return false;
        }
        [$subnet, $bits] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        $bits = $bits === null ? $maxBits : (int) $bits;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = 0xff << (8 - $rest) & 0xff;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    /**
     * Schlüssel für Rate Limiting und Sperren je Client: IPv4 unverändert, IPv4-gemappte IPv6-Adressen
     * als IPv4, sonstige IPv6-Adressen als /64-Präfix. Ein Anschluss erhält üblicherweise ein ganzes /64,
     * ohne Zusammenfassung könnte ein Client jedes Limit durch Wechsel der Adresse umgehen.
     * Keine gültige IP-Adresse: Eingabe unverändert.
     */
    public static function clientKey(string $ip): string
    {
        $bin = @inet_pton(trim($ip));
        if ($bin === false) {
            return $ip;
        }
        if (strlen($bin) === 4) {
            return (string) inet_ntop($bin);
        }
        if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($bin, 12));
        }

        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}
