<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Finding;
use CookieManagerKit\Scan\UnsafeUrl;
use CookieManagerKit\Scan\ScanResult;
use CookieManagerKit\Scan\FetchFailed;
use CookieManagerKit\Scan\FetchResult;
use CookieManagerKit\Scan\ScanContext;
use CookieManagerKit\Scan\CrawlScanner;

final class CrawlScannerTest extends TestCase
{
    /**
     * @param list<Finding> $found
     *
     * @return list<string>
     */
    private static function summary(array $found): array
    {
        $rows = array_map(static fn(Finding $f): string => $f->kind . ' ' . $f->name . ' ' . $f->host, $found);
        sort($rows);

        return $rows;
    }

    public function testCombinesTagsAndCookiesFromEveryPage(): void
    {
        $fetcher = new FakeFetcher([
            'https://e.com/' => FakeFetcher::page('<script src="https://www.googletagmanager.com/gtm.js"></script>', ['set-cookie' => ['_ga=1; Domain=.e.com']]),
            'https://e.com/about' => FakeFetcher::page('<img src="https://pix.example.net/p.gif">', ['set-cookie' => ['sid=2']]),
        ]);

        $result = (new CrawlScanner($fetcher))->scan(new ScanContext('e.com', ['https://e.com/', 'https://e.com/about']));

        $this->assertSame([
            'cookie _ga e.com',
            'cookie sid e.com',
            'image pix.example.net pix.example.net',
            'script www.googletagmanager.com www.googletagmanager.com',
        ], self::summary($result->findings));
        $this->assertSame(2, $result->pagesScanned);
        $this->assertSame(0, $result->pagesFailed);
        $this->assertTrue($result->isComplete());
    }

    public function testTheSameFindingOnManyPagesIsReportedOnceWithTheFirstPage(): void
    {
        $tag = '<script src="https://cdn.example.net/a.js"></script>';
        $fetcher = new FakeFetcher(['https://e.com/a' => FakeFetcher::page($tag), 'https://e.com/b' => FakeFetcher::page($tag)]);

        $result = (new CrawlScanner($fetcher))->scan(new ScanContext('e.com', ['https://e.com/a', 'https://e.com/b']));

        $this->assertCount(1, $result->findings);
        $this->assertSame('/a', $result->findings[0]->page);
    }

    public function testRedirectsErrorsAndTransportFailuresMakeTheCrawlIncomplete(): void
    {
        $fetcher = new FakeFetcher([
            'https://e.com/ok' => FakeFetcher::page('<p>fine</p>'),
            'https://e.com/moved' => new FetchResult(301, ['location' => ['https://e.com/new']], ''),
            'https://e.com/missing' => FakeFetcher::page('<p>404</p>', [], 404),
            'https://e.com/slow' => new FetchFailed('timed out'),
            'https://e.com/off' => new UnsafeUrl('off host'),
        ]);

        $result = (new CrawlScanner($fetcher))->scan(new ScanContext('e.com', [
            'https://e.com/ok',
            'https://e.com/moved',
            'https://e.com/missing',
            'https://e.com/slow',
            'https://e.com/off',
        ]));

        $this->assertSame(1, $result->pagesScanned);
        $this->assertSame(4, $result->pagesFailed);
        $this->assertFalse($result->isComplete());
    }

    public function testAnErrorPageStillContributesTheCookiesItSets(): void
    {
        $fetcher = new FakeFetcher(['https://e.com/missing' => FakeFetcher::page('<p>404</p>', ['set-cookie' => ['err=1']], 404)]);

        $result = (new CrawlScanner($fetcher))->scan(new ScanContext('e.com', ['https://e.com/missing']));

        $this->assertSame(['cookie err e.com'], self::summary($result->findings));
    }

    public function testANonHtmlResponseIsCountedButNotParsed(): void
    {
        $fetcher = new FakeFetcher(['https://e.com/a.pdf' => new FetchResult(200, ['content-type' => ['application/pdf']], '<script src="https://cdn.example.net/x.js"></script>')]);

        $result = (new CrawlScanner($fetcher))->scan(new ScanContext('e.com', ['https://e.com/a.pdf']));

        $this->assertSame([], $result->findings);
        $this->assertSame(1, $result->pagesScanned);
    }

    public function testAZeroTagPageAndAnEmptyBodyAreFineAndComplete(): void
    {
        $fetcher = new FakeFetcher(['https://e.com/' => FakeFetcher::page('<p>hello</p>'), 'https://e.com/empty' => FakeFetcher::page('')]);

        $result = (new CrawlScanner($fetcher))->scan(new ScanContext('e.com', ['https://e.com/', 'https://e.com/empty']));

        $this->assertSame([], $result->findings);
        $this->assertTrue($result->isComplete());
    }

    public function testTheTimeBudgetStopsNewFetchesAndCountsTheRestAsFailed(): void
    {
        $fetcher = new FakeFetcher([
            'https://e.com/1' => FakeFetcher::page('<p>1</p>'),
            'https://e.com/2' => FakeFetcher::page('<p>2</p>'),
            'https://e.com/3' => FakeFetcher::page('<p>3</p>'),
        ]);

        // The clock moves on 100 s every time it is read, so a 150 s budget lets the first page through
        // (0 s elapsed at the first check, 100 s at the second) and stops before the third.
        $now = 1000;
        $scanner = new CrawlScanner($fetcher, function () use (&$now): int {
            $now += 100;

            return $now - 100;
        }, 150);

        $result = $scanner->scan(new ScanContext('e.com', ['https://e.com/1', 'https://e.com/2', 'https://e.com/3']));

        $this->assertLessThan(3, count($fetcher->requested));
        $this->assertSame(3 - $result->pagesScanned, $result->pagesFailed);
        $this->assertFalse($result->isComplete());
    }

    public function testHostileCookieNamesFlowThroughCappedAndVerbatim(): void
    {
        $fetcher = new FakeFetcher(['https://e.com/' => FakeFetcher::page('<p>x</p>', ['set-cookie' => ['"><svg/onload/alert(1)>=1', str_repeat('n', 10000) . '=1']])]);

        $result = (new CrawlScanner($fetcher))->scan(new ScanContext('e.com', ['https://e.com/']));

        $this->assertSame('"><svg/onload/alert(1)>', $result->findings[0]->name);
        $this->assertSame(128, strlen($result->findings[1]->name));
    }

    public function testFindingsCarryThePathNotTheQueryString(): void
    {
        $fetcher = new FakeFetcher(['https://e.com/p?token=abc' => FakeFetcher::page('<script src="https://cdn.example.net/a.js"></script>', ['set-cookie' => ['sid=1']])]);

        $result = (new CrawlScanner($fetcher))->scan(new ScanContext('e.com', ['https://e.com/p?token=abc']));

        $this->assertCount(2, $result->findings);

        foreach ($result->findings as $finding) {
            $this->assertSame('/p', $finding->page);
        }
    }

    public function testBudgetExhaustionIsIncompleteEvenIfNoPageFailedBeforeIt(): void
    {
        $this->assertTrue((new ScanResult([], 1, 0))->isComplete());
        $this->assertFalse((new ScanResult([], 1, 1))->isComplete());
        $this->assertFalse((new ScanResult([], 1, 0, false))->isComplete());
    }

    public function testACrawlThatScannedNothingIsNotComplete(): void
    {
        $result = (new CrawlScanner(new FakeFetcher([])))->scan(new ScanContext('e.com', []));

        $this->assertFalse($result->isComplete());
    }
}
