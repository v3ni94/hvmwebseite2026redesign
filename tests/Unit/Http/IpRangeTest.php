<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Http;

use Hvm\Http\IpRange;
use PHPUnit\Framework\TestCase;

final class IpRangeTest extends TestCase
{
    public function testClientKeyKeepsIpv4(): void
    {
        self::assertSame('203.0.113.5', IpRange::clientKey('203.0.113.5'));
    }

    public function testClientKeyGroupsIpv6ByPrefix64(): void
    {
        self::assertSame('2001:db8:1:2::/64', IpRange::clientKey('2001:db8:1:2:aaaa::1'));
        self::assertSame('2001:db8:1:2::/64', IpRange::clientKey('2001:DB8:1:2:ffff:ffff:ffff:ffff'));
        self::assertNotSame(IpRange::clientKey('2001:db8:1:2::1'), IpRange::clientKey('2001:db8:1:3::1'));
    }

    public function testClientKeyMapsIpv4MappedIpv6ToIpv4(): void
    {
        self::assertSame('198.51.100.7', IpRange::clientKey('::ffff:198.51.100.7'));
    }

    public function testClientKeyLeavesOtherIdentifiersUntouched(): void
    {
        self::assertSame('kein-ip', IpRange::clientKey('kein-ip'));
    }

    public function testInvalidPrefixLengthNeverMatches(): void
    {
        // Tippfehler in TRUSTED_PROXIES oder ADMIN_IP_ALLOWLIST dürfen nicht zu /0 (alle Adressen) werden
        self::assertFalse(IpRange::matches('203.0.113.5', '172.30.90.0/abc'));
        self::assertFalse(IpRange::matches('203.0.113.5', '172.30.90.0/'));
        self::assertFalse(IpRange::matches('203.0.113.5', '172.30.90.0/ 24'));
        self::assertFalse(IpRange::matches('203.0.113.5', '172.30.90.0/-1'));
        self::assertTrue(IpRange::matches('172.30.90.7', '172.30.90.0/24'));
        self::assertTrue(IpRange::matches('203.0.113.5', '0.0.0.0/0'), 'ausdrückliches /0 bleibt möglich');
    }
}
