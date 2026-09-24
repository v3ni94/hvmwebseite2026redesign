<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Security;

use Hvm\Security\FileRateLimiter;
use Hvm\Tests\Unit\TestCase;

final class FileRateLimiterTest extends TestCase
{
    public function testCountsWithinWindowAndResets(): void
    {
        $dir = sys_get_temp_dir() . '/hvm-rl-' . bin2hex(random_bytes(4));
        $limiter = new FileRateLimiter($dir, 'fiktiver-schluessel');
        $now = 1_800_000_000;

        self::assertFalse($limiter->tooMany('test', '198.51.100.7', 3, 60, $now));
        self::assertSame(1, $limiter->hit('test', '198.51.100.7', 60, $now));
        self::assertSame(2, $limiter->hit('test', '198.51.100.7', 60, $now + 10));
        self::assertSame(3, $limiter->hit('test', '198.51.100.7', 60, $now + 20));
        self::assertTrue($limiter->tooMany('test', '198.51.100.7', 3, 60, $now + 30));
        self::assertFalse($limiter->tooMany('test', '198.51.100.8', 3, 60, $now + 30));
        // Fenster abgelaufen
        self::assertFalse($limiter->tooMany('test', '198.51.100.7', 3, 60, $now + 61));
        self::assertSame(1, $limiter->hit('test', '198.51.100.7', 60, $now + 61));

        $limiter->reset('test', '198.51.100.7');
        self::assertSame(0, $limiter->hits('test', '198.51.100.7', 60, $now + 62));

        foreach (glob($dir . '/*') ?: [] as $file) {
            self::assertStringNotContainsString('198.51.100', basename($file));
            unlink($file);
        }
        @rmdir($dir);
    }
}
