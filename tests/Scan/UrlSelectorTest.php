<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\UrlSelector;

final class UrlSelectorTest extends TestCase
{
    public function testKeepsOrderAndDropsDuplicates(): void
    {
        $picked = UrlSelector::select(['https://e.com/a', 'https://e.com/b', 'https://e.com/a'], [], 10);

        $this->assertSame(['https://e.com/a', 'https://e.com/b'], $picked['urls']);
        $this->assertFalse($picked['truncated']);
        $this->assertSame(0, $picked['excluded']);
    }

    public function testCapsAndReportsTruncation(): void
    {
        $urls = array_map(static fn(int $i): string => 'https://e.com/p' . $i, range(1, 10));
        $picked = UrlSelector::select($urls, [], 4);

        $this->assertCount(4, $picked['urls']);
        $this->assertTrue($picked['truncated']);
    }

    public function testExactlyTheCapIsNotTruncated(): void
    {
        $urls = ['https://e.com/a', 'https://e.com/b'];

        $this->assertFalse(UrlSelector::select($urls, [], 2)['truncated']);
    }

    public function testExcludesByExactPathAndItsChildren(): void
    {
        $urls = ['https://e.com/', 'https://e.com/members', 'https://e.com/members/profile', 'https://e.com/membership', 'https://e.com/about'];
        $picked = UrlSelector::select($urls, ['/members'], 10);

        $this->assertSame(['https://e.com/', 'https://e.com/membership', 'https://e.com/about'], $picked['urls']);
        $this->assertSame(2, $picked['excluded']);
    }

    public function testExcludesByWildcardPrefix(): void
    {
        $urls = ['https://e.com/shop', 'https://e.com/shop/a', 'https://e.com/shopping', 'https://e.com/blog'];

        $this->assertSame(['https://e.com/shop', 'https://e.com/blog'], UrlSelector::select($urls, ['/shop/*', '/shopping*'], 10)['urls']);
    }

    public function testQueryStringsDoNotAffectMatching(): void
    {
        $this->assertSame([], UrlSelector::select(['https://e.com/members?x=1'], ['/members'], 10)['urls']);
    }

    public function testExcludedPagesDoNotUseUpTheCap(): void
    {
        $urls = ['https://e.com/x/1', 'https://e.com/x/2', 'https://e.com/keep'];
        $picked = UrlSelector::select($urls, ['/x/*'], 1);

        $this->assertSame(['https://e.com/keep'], $picked['urls']);
        $this->assertFalse($picked['truncated']);
    }

    public function testUnparseableUrlsAreSkipped(): void
    {
        $this->assertSame(['https://e.com/ok'], UrlSelector::select(['https://', 'https://e.com/ok', ''], [], 10)['urls']);
    }
}
