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
}
