<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Finding;
use CookieManagerKit\Scan\CookieHeader;

final class CookieHeaderTest extends TestCase
{
    /**
     * @param list<Finding> $found
     *
     * @return list<string>
     */
    private static function summary(array $found): array
    {
        return array_map(static fn(Finding $f): string => $f->kind . ' ' . $f->name . ' ' . $f->host, $found);
    }

    public function testNameAndHostFromSetCookieLines(): void
    {
        $found = CookieHeader::findings([
            '_ga=GA1.2.3; Domain=.example.com; Path=/; Max-Age=63072000',
            'sid=abc; Path=/; HttpOnly; Secure',
        ], 'www.example.com', 'https://www.example.com/');

        $this->assertSame(['cookie _ga example.com', 'cookie sid www.example.com'], self::summary($found));
        $this->assertSame('https://www.example.com/', $found[0]->page);
    }

    public function testADomainAttributeForAnotherSiteIsIgnored(): void
    {
        $found = CookieHeader::findings(['x=1; Domain=evil.example.net'], 'example.com', 'https://example.com/');

        $this->assertSame(['cookie x example.com'], self::summary($found));
    }

    public function testNeverReadsTheValue(): void
    {
        $found = CookieHeader::findings(['token=SECRET-VALUE-123; Path=/'], 'example.com', 'https://example.com/');

        $this->assertStringNotContainsString('SECRET', serialize($found));
    }

    public function testMarkupInANameIsKeptVerbatim(): void
    {
        $found = CookieHeader::findings(['<b>x</b>=1'], 'example.com', 'https://example.com/');

        $this->assertSame('<b>x</b>', $found[0]->name);
    }

    public function testEmptyAndMalformedLinesAreSkipped(): void
    {
        $found = CookieHeader::findings(['', '=novalue', 'noequals', "\x00=1", '   ; Path=/', 'ok=1'], 'example.com', 'https://example.com/');

        $this->assertSame(['cookie ok example.com'], self::summary($found));
    }

    public function testRepeatedNamesAreReportedOnce(): void
    {
        $found = CookieHeader::findings(['a=1', 'a=2; Path=/x'], 'example.com', 'https://example.com/');

        $this->assertCount(1, $found);
    }

    public function testHostComparisonIsCaseInsensitive(): void
    {
        $found = CookieHeader::findings(['a=1; Domain=.EXAMPLE.com', 'b=1'], 'WWW.Example.COM', 'https://www.example.com/');

        $this->assertSame(['cookie a example.com', 'cookie b www.example.com'], self::summary($found));
    }
}
