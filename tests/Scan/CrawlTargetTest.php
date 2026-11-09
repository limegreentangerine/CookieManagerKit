<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\UrlGuard;
use CookieManagerKit\Scan\UnsafeUrl;
use CookieManagerKit\Scan\CrawlTarget;

final class CrawlTargetTest extends TestCase
{
    public function testTheFirstNonEmptyCandidateWinsTrimmedOfSlashes(): void
    {
        $this->assertSame('https://e.com', CrawlTarget::pick(['', '  ', ' https://e.com/ ', 'https://other.example.net']));
    }

    public function testNoCanonicalUrlFailsWithTheFixedMessage(): void
    {
        $this->expectException(UnsafeUrl::class);
        $this->expectExceptionMessage(CrawlTarget::MISSING);

        CrawlTarget::pick(['', ' ']);
    }

    public function testTheCrawlOriginUsesTheGuardsNormalisedHost(): void
    {
        $guard = UrlGuard::forSite('https://EXAMPLE.com.:8443', static fn(string $host): string => '93.184.216.34');

        $this->assertSame('https://example.com:8443', CrawlTarget::origin('https://EXAMPLE.com.:8443', $guard));
    }

    public function testTheOriginOmitsAnAbsentPort(): void
    {
        $guard = UrlGuard::forSite('http://example.com', static fn(string $host): string => '93.184.216.34');

        $this->assertSame('http://example.com', CrawlTarget::origin('http://example.com', $guard));
    }
}
