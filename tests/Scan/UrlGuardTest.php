<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\UrlGuard;
use CookieManagerKit\Scan\UnsafeUrl;
use PHPUnit\Framework\Attributes\DataProvider;

final class UrlGuardTest extends TestCase
{
    private static function guard(string $base = 'https://example.com', string $ip = '203.0.113.7'): UrlGuard
    {
        return UrlGuard::forSite($base, static fn(string $host): string => $ip);
    }

    public function testResolvesTheSiteHostExactlyOnce(): void
    {
        $calls = 0;
        $guard = UrlGuard::forSite('https://example.com', static function () use (&$calls): string {
            $calls++;

            return '203.0.113.7';
        });

        $guard->check('https://example.com/a');
        $guard->check('https://example.com/b');

        $this->assertSame(1, $calls);
        $this->assertSame('example.com:443:203.0.113.7', $guard->curlResolve());
    }

    public function testDefaultPortsFollowTheScheme(): void
    {
        $this->assertSame('example.com:80:203.0.113.7', self::guard('http://example.com')->curlResolve());
        $this->assertSame('example.com:8080:203.0.113.7', self::guard('http://example.com:8080')->curlResolve());
    }

    public function testASitePageOnTheSameHostPasses(): void
    {
        self::guard()->check('https://example.com/about?x=1');
        self::guard()->check('https://EXAMPLE.com/');

        $this->addToAssertionCount(2);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refused(): array
    {
        return [
            'another host' => ['https://evil.example.net/x'],
            'a subdomain' => ['https://cdn.example.com/x'],
            'the www twin' => ['https://www.example.com/x'],
            'wrong scheme' => ['http://example.com/x'],
            'wrong port' => ['https://example.com:8443/x'],
            'userinfo' => ['https://user:pass@example.com/x'],
            'userinfo trick' => ['https://example.com@evil.example.net/x'],
            'internal ip' => ['https://169.254.169.254/latest/meta-data'],
            'loopback' => ['https://127.0.0.1/'],
            'ftp' => ['ftp://example.com/x'],
            'file' => ['file:///etc/passwd'],
            'relative' => ['/x'],
            'garbage' => ['https://'],
            'trailing dot escapes the pin' => ['https://example.com./x'],
            'backslash authority' => ['https://evil.example.net\\@example.com/x'],
            'backslash path' => ['https://example.com\\.evil.example.net/x'],
            'space in host' => ['https://example.com /x'],
            'newline in url' => ["https://example.com/x\r\nHost: evil"],
            'nul in host' => ["https://example.com\0.evil.example.net/"],
            'port as userinfo' => ['https://example.com:443@evil.example.net/'],
            'doubled port' => ['https://example.com:443:80/'],
            'ipv6 literal' => ['https://[::1]/'],
            'ipv4 mapped ipv6 literal' => ['https://[::ffff:169.254.169.254]/'],
            'decimal ip' => ['https://2130706433/'],
            'hex ip' => ['https://0x7f000001/'],
            'percent encoded host' => ['https://example%2ecom/'],
            'scheme relative' => ['//example.com/x'],
            'empty' => [''],
        ];
    }

    #[DataProvider('refused')]
    public function testRefusesAnythingButTheSitesOwnPages(string $url): void
    {
        $this->expectException(UnsafeUrl::class);

        self::guard()->check($url);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badAddresses(): array
    {
        return [
            'link local' => ['169.254.169.254'],
            'this network' => ['0.0.0.0'],
            'multicast' => ['224.0.0.1'],
            'reserved' => ['240.0.0.1'],
            'broadcast' => ['255.255.255.255'],
            'cloud metadata' => ['169.254.169.254'],
            'last link local' => ['169.254.255.255'],
            'this network high' => ['0.255.255.255'],
            'garbage address' => ['999.1.1.1'],
            'ipv6 loopback' => ['::1'],
            'ipv4 mapped ipv6' => ['::ffff:169.254.169.254'],
            'ipv6 link local' => ['fe80::1'],
            'ipv6 ula' => ['fd00::1'],
            'empty address' => [''],
        ];
    }

    #[DataProvider('badAddresses')]
    public function testRefusesSiteHostsThatResolveToNeverLegitimateAddresses(string $ip): void
    {
        $this->expectException(UnsafeUrl::class);

        UrlGuard::forSite('https://example.com', static fn(string $host): string => $ip);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function privateAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'private 10' => ['10.0.0.1'],
            'private 172' => ['172.20.0.5'],
            'private 192' => ['192.168.1.1'],
            'shared 100.64' => ['100.64.0.1'],
            'ietf 192.0.0' => ['192.0.0.8'],
            'benchmark 198.18' => ['198.18.0.1'],
        ];
    }

    #[DataProvider('privateAddresses')]
    public function testRefusesNonPublicSiteAddressesByDefault(string $ip): void
    {
        $this->expectException(UnsafeUrl::class);

        self::guard('https://example.com', $ip);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function developmentAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'private 10' => ['10.1.2.3'],
            'private 172' => ['172.20.0.5'],
            'private 192' => ['192.168.1.20'],
        ];
    }

    #[DataProvider('developmentAddresses')]
    public function testThePrivateNetworkFlagAllowsLoopbackAndRfc1918(string $ip): void
    {
        $guard = UrlGuard::forSite('https://example.com', static fn(string $host): string => $ip, true);

        $this->assertSame('example.com:443:' . $ip, $guard->curlResolve());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function neverAllowed(): array
    {
        return [
            'metadata' => ['169.254.169.254'],
            'multicast' => ['224.0.0.1'],
            'reserved' => ['240.0.0.1'],
            'this network' => ['0.0.0.0'],
            'shared 100.64' => ['100.64.0.1'],
            'benchmark' => ['198.18.0.1'],
            'ipv6 loopback' => ['::1'],
            'mapped' => ['::ffff:127.0.0.1'],
        ];
    }

    #[DataProvider('neverAllowed')]
    public function testThePrivateNetworkFlagNeverAllowsOtherRanges(string $ip): void
    {
        $this->expectException(UnsafeUrl::class);

        UrlGuard::forSite('https://example.com', static fn(string $host): string => $ip, true);
    }

    public function testABaseUrlWithPortZeroIsRefused(): void
    {
        $this->expectException(UnsafeUrl::class);

        self::guard('https://example.com:0');
    }

    public function testASiteThatDoesNotResolveIsRefused(): void
    {
        $this->expectException(UnsafeUrl::class);

        UrlGuard::forSite('https://example.com', static fn(string $host): ?string => null);
    }

    public function testABaseUrlThatIsNotPlainHttpIsRefused(): void
    {
        foreach (['ftp://example.com', 'https://u:p@example.com', 'not a url', '', 'https://[::1]/'] as $base) {
            try {
                UrlGuard::forSite($base, static fn(string $host): string => '203.0.113.7');
                $this->fail('accepted ' . $base);
            } catch (UnsafeUrl) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testABaseUrlWithATrailingDotPinsTheNormalisedHost(): void
    {
        $this->assertSame('example.com:443:203.0.113.7', self::guard('https://example.com.')->curlResolve());
    }

    public function testTheResolverIsGivenTheNormalisedHost(): void
    {
        $seen = null;
        UrlGuard::forSite('https://EXAMPLE.com/', static function (string $host) use (&$seen): string {
            $seen = $host;

            return '203.0.113.7';
        });

        $this->assertSame('example.com', $seen);
    }

    public function testAnIpLiteralSiteIsPinnedToItself(): void
    {
        $guard = UrlGuard::forSite('http://192.168.1.20:8080', static fn(string $host): string => $host, true);

        $this->assertSame('192.168.1.20:8080:192.168.1.20', $guard->curlResolve());
        $guard->check('http://192.168.1.20:8080/x');
    }
}
