<?php

declare(strict_types=1);

namespace Hvm\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Zentrale Zeitquelle (UTC). In Tests per freeze() feststellbar.
 */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Aktuelle Zeit in der Anzeigezeitzone (Europe/Berlin).
     */
    public static function local(): DateTimeImmutable
    {
        return self::now()->setTimezone(new DateTimeZone('Europe/Berlin'));
    }

    public static function freeze(DateTimeImmutable|string $at): void
    {
        self::$frozen = is_string($at)
            ? new DateTimeImmutable($at, new DateTimeZone('UTC'))
            : $at->setTimezone(new DateTimeZone('UTC'));
    }

    public static function unfreeze(): void
    {
        self::$frozen = null;
    }
}
