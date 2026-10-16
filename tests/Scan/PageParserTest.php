<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Finding;
use CookieManagerKit\Scan\PageParser;

final class PageParserTest extends TestCase
{
    /**
     * @param list<Finding> $found
     *
     * @return list<string>
     */
    private static function summary(array $found): array
    {
        $rows = array_map(static fn(Finding $f): string => $f->kind . ' ' . $f->host, $found);
        sort($rows);

        return $rows;
    }

    public function testFindsThirdPartyTagsByKind(): void
    {
        $html = '<html><head>'
            . '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-1"></script>'
            . '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=X">'
            . '<link rel="canonical" href="https://other.example.org/">'
            . '</head><body>'
            . '<img src="//stats.example.net/p.gif">'
            . '<iframe src="https://www.youtube.com/embed/x"></iframe>'
            . '<script src="/local.js"></script>'
            . '<script>fetch("https://api.segment.io/v1/t")</script>'
            . '</body></html>';

        $found = PageParser::findings($html, 'https://example.com/about', 'example.com');

        $this->assertSame([
            'iframe www.youtube.com',
            'image stats.example.net',
            'resource fonts.googleapis.com',
            'script api.segment.io',
            'script www.googletagmanager.com',
        ], self::summary($found));
        $this->assertSame('https://example.com/about', $found[0]->page);
    }

    public function testNameIsTheHostForTagKinds(): void
    {
        $found = PageParser::findings('<script src="https://cdn.example.net/a.js"></script>', 'https://example.com/', 'example.com');

        $this->assertSame('cdn.example.net', $found[0]->name);
    }

    public function testTheSitesOwnHostAndItsWwwTwinAreNotFindings(): void
    {
        $html = '<script src="https://www.example.com/a.js"></script><script src="https://example.com/b.js"></script>';

        $this->assertSame([], PageParser::findings($html, 'https://example.com/', 'example.com'));
    }

    public function testUnsafeSchemesAreIgnored(): void
    {
        $html = '<img src="data:image/png;base64,AAAA"><script src="javascript:alert(1)"></script><iframe src="about:blank"></iframe>';

        $this->assertSame([], PageParser::findings($html, 'https://example.com/', 'example.com'));
    }

    public function testEachKindAndHostIsReportedOncePerPage(): void
    {
        $html = '<script src="https://cdn.example.net/a.js"></script><script src="https://cdn.example.net/b.js"></script><img src="https://cdn.example.net/c.gif">';

        $this->assertSame(['image cdn.example.net', 'script cdn.example.net'], self::summary(PageParser::findings($html, 'https://example.com/', 'example.com')));
    }

    public function testOnlyResourceLinkRelationsCount(): void
    {
        $html = '<link rel="preconnect" href="https://fonts.gstatic.com"><link rel="alternate" href="https://alt.example.net/">'
            . '<link rel="author" href="https://who.example.net/"><link rel="dns-prefetch" href="//ads.example.net">';

        $this->assertSame(['resource ads.example.net', 'resource fonts.gstatic.com'], self::summary(PageParser::findings($html, 'https://example.com/', 'example.com')));
    }

    public function testInlineScriptsContributeHostsTheyMention(): void
    {
        $html = '<script>var u="//tracker.example.net/t.js"; var v = "https://pix.example.org/p";</script>';

        $this->assertSame(['script pix.example.org', 'script tracker.example.net'], self::summary(PageParser::findings($html, 'https://example.com/', 'example.com')));
    }

    public function testAtMostTwoHundredFindingsPerPage(): void
    {
        $html = '';

        for ($i = 0; $i < 300; $i++) {
            $html .= '<script src="https://h' . $i . '.example.net/a.js"></script>';
        }

        $this->assertCount(200, PageParser::findings($html, 'https://example.com/', 'example.com'));
    }

    public function testGarbageEmptyAndBinaryInputGiveNothingAndNeverThrow(): void
    {
        foreach (['', '   ', "\xff\xfe<<>>\x00", '<<<<<>>>>>', str_repeat('<div>', 5000), '<script src=', '<script src="https://cdn.example.net/a.js'] as $html) {
            $found = PageParser::findings($html, 'https://example.com/', 'example.com');

            $this->assertContainsOnlyInstancesOf(Finding::class, $found);
        }

        $this->assertSame([], PageParser::findings('', 'https://example.com/', 'example.com'));
    }

    public function testHostileAttributeValuesNeverBecomeHosts(): void
    {
        $html = '<script src="https://&lt;script&gt;.example.net/x.js"></script><img src="https://exa mple.com/x.gif"><iframe src="https://[::1]/x"></iframe>';

        $this->assertSame([], PageParser::findings($html, 'https://example.com/', 'example.com'));
    }
}
