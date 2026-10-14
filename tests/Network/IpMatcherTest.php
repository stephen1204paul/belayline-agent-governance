<?php

declare(strict_types=1);

namespace Belayline\Tests\Network;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Belayline\Network\IpMatcher;

final class IpMatcherTest extends TestCase
{
    public function testExactV4AndV6AddressesMatch(): void
    {
        $this->assertTrue(IpMatcher::matches('203.0.113.7', ['203.0.113.7']));
        $this->assertFalse(IpMatcher::matches('203.0.113.8', ['203.0.113.7']));
        $this->assertTrue(IpMatcher::matches('2001:db8::1', ['2001:0db8:0:0:0:0:0:1']));
        $this->assertFalse(IpMatcher::matches('2001:db8::2', ['2001:db8::1']));
    }

    public function testV4Cidr(): void
    {
        $this->assertTrue(IpMatcher::matches('10.1.2.3', ['10.0.0.0/8']));
        $this->assertTrue(IpMatcher::matches('192.168.1.200', ['192.168.1.128/25']));
        $this->assertFalse(IpMatcher::matches('192.168.1.100', ['192.168.1.128/25']));
        $this->assertTrue(IpMatcher::matches('8.8.8.8', ['0.0.0.0/0']));
        $this->assertFalse(IpMatcher::matches('11.0.0.1', ['10.0.0.0/8']));
    }

    public function testV6Cidr(): void
    {
        $this->assertTrue(IpMatcher::matches('2001:db8:abcd::5', ['2001:db8::/32']));
        $this->assertFalse(IpMatcher::matches('2001:db9::5', ['2001:db8::/32']));
        $this->assertTrue(IpMatcher::matches('2001:db8:0:1:ffff::1', ['2001:db8:0:1::/64']));
        $this->assertFalse(IpMatcher::matches('2001:db8:0:2::1', ['2001:db8:0:1::/64']));
        $this->assertTrue(IpMatcher::matches('fe80::1', ['::/0']));
    }

    public function testFamiliesNeverCrossMatchExceptMappedV4(): void
    {
        $this->assertFalse(IpMatcher::matches('10.0.0.1', ['::/0']));
        $this->assertFalse(IpMatcher::matches('2001:db8::1', ['0.0.0.0/0']));
        $this->assertTrue(IpMatcher::matches('::ffff:203.0.113.7', ['203.0.113.0/24']));
    }

    public function testUnparseableClientMatchesNothing(): void
    {
        $this->assertFalse(IpMatcher::matches(null, ['0.0.0.0/0']));
        $this->assertFalse(IpMatcher::matches('', ['0.0.0.0/0']));
        $this->assertFalse(IpMatcher::matches('not-an-ip', ['0.0.0.0/0']));
    }

    /** @return array<string, array{string}> */
    public static function malformed(): array
    {
        return [
            'word' => ['localhost'],
            'short v4' => ['10.0.0'],
            'octet range' => ['256.1.1.1'],
            'v4 prefix too long' => ['10.0.0.0/33'],
            'v6 prefix too long' => ['2001:db8::/129'],
            'negative prefix' => ['10.0.0.0/-1'],
            'non numeric prefix' => ['10.0.0.0/abc'],
            'empty prefix' => ['10.0.0.0/'],
            'two slashes' => ['10.0.0.0/8/8'],
            'internal space' => ['10.0.0.1 10.0.0.2'],
            'range' => ['10.0.0.1-10.0.0.9'],
        ];
    }

    #[DataProvider('malformed')]
    public function testMalformedEntriesAreInvalid(string $entry): void
    {
        $this->assertFalse(IpMatcher::isValidEntry($entry));
        $parsed = IpMatcher::parseList("203.0.113.1\n" . $entry);
        $this->assertSame([$entry], $parsed['invalid']);
        $this->assertSame(['203.0.113.1'], $parsed['valid']);
    }

    public function testParseListTrimsSkipsBlanksAndDeduplicates(): void
    {
        $parsed = IpMatcher::parseList("  10.0.0.0/8 \r\n\r\n2001:db8::/32\n10.0.0.0/8\n");
        $this->assertSame(['10.0.0.0/8', '2001:db8::/32'], $parsed['valid']);
        $this->assertSame([], $parsed['invalid']);
        $this->assertSame(['valid' => [], 'invalid' => []], IpMatcher::parseList(" \n"));
    }

    public function testClientIpIsRemoteAddrWithoutTrustedProxies(): void
    {
        $this->assertSame('198.51.100.9', IpMatcher::clientIp('198.51.100.9', '203.0.113.7', []));
        $this->assertNull(IpMatcher::clientIp(null, '203.0.113.7', []));
        $this->assertNull(IpMatcher::clientIp('garbage', null, ['10.0.0.0/8']));
    }

    public function testForwardedForIgnoredWhenRemoteIsNotATrustedProxy(): void
    {
        $this->assertSame('198.51.100.9', IpMatcher::clientIp('198.51.100.9', '203.0.113.7', ['10.0.0.0/8']));
    }

    public function testForwardedForUsesRightMostUntrustedHop(): void
    {
        $trusted = ['10.0.0.0/8', '172.16.0.5'];

        // The left-most hop is attacker-supplied; the right-most untrusted one is real.
        $this->assertSame(
            '203.0.113.7',
            IpMatcher::clientIp('10.0.0.1', '1.2.3.4, 203.0.113.7, 172.16.0.5', $trusted),
        );
        $this->assertSame('203.0.113.7', IpMatcher::clientIp('10.0.0.1', '203.0.113.7', $trusted));
    }

    public function testForwardedForAllTrustedYieldsLeftMostAndAbsentYieldsRemote(): void
    {
        $this->assertSame('10.0.0.9', IpMatcher::clientIp('10.0.0.1', '10.0.0.9, 10.0.0.2', ['10.0.0.0/8']));
        $this->assertSame('10.0.0.1', IpMatcher::clientIp('10.0.0.1', null, ['10.0.0.0/8']));
        $this->assertSame('10.0.0.1', IpMatcher::clientIp('10.0.0.1', '  ', ['10.0.0.0/8']));
    }

    public function testMalformedHopOnTheWalkFailsClosed(): void
    {
        $this->assertNull(IpMatcher::clientIp('10.0.0.1', '203.0.113.7, unknown', ['10.0.0.0/8']));
    }
}
