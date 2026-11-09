<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Scanner;
use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Scan\UnsafeUrl;
use CookieManagerKit\Scan\UrlSource;
use CookieManagerKit\Entity\Settings;
use CookieManagerKit\Scan\Classifier;
use CookieManagerKit\Scan\ScanResult;
use CookieManagerKit\Scan\CrawlRunner;
use CookieManagerKit\Scan\FetchFailed;
use CookieManagerKit\Scan\FetchResult;
use CookieManagerKit\Scan\ScanContext;
use CookieManagerKit\Scan\CrawlScanner;
use CookieManagerKit\Entity\ScanFinding;
use CookieManagerKit\Scan\FindingIngestor;

/**
 * End to end through the runner: real CrawlScanner, real FindingIngestor and UrlSelector; only the network (a fake
 * fetcher), the page list and the database are faked. A crawl that did not see the whole site must leave every
 * existing finding exactly as it was; only a crawl that saw everything may mark unseen crawl findings gone.
 */
final class CrawlRunnerPartialCrawlTest extends TestCase
{
    private InMemoryFindingStore $store;

    private static function ok(string $cookie = 'live'): FetchResult
    {
        return FakeFetcher::page('<p>hi</p>', ['set-cookie' => [$cookie . '=1']]);
    }

    /**
     * @param list<string>                          $pages     what the page source returns
     * @param array<string, FetchResult|\Throwable> $responses
     */
    private function crawl(array $pages, array $responses, ?Settings $settings = null, ?\Closure $clock = null, ?Scanner $scanner = null): ScanRun
    {
        $source = new class($pages) implements UrlSource {
            public function __construct(private readonly array $pages) {}

            public function all(): array
            {
                return $this->pages;
            }

            public function dropped(): int
            {
                return 0;
            }
        };

        return (new CrawlRunner(
            EmFactory::make($this),
            $settings ?? new Settings(),
            $source,
            $scanner ?? new CrawlScanner(new FakeFetcher($responses), $clock),
            new FindingIngestor($this->store, new Classifier([], static fn(string $name): bool => false)),
            new RecordingAlerts(),
            'e.com',
            static fn(): ?int => null,
            static fn(): int => 1000000,
            static fn(): int => 0,
        ))->run();
    }

    private function assertNothingGone(): void
    {
        foreach (['old', 'both', 'runtime'] as $name) {
            $this->assertNotSame(ScanFinding::GONE, $this->store->get('cookie', $name, 'e.com')?->getStatus(), $name . ' was marked gone by a partial crawl');
            $this->assertSame(ScanFinding::UNCLASSIFIED, $this->store->get('cookie', $name, 'e.com')?->getStatus());
        }

        $this->assertTrue($this->store->get('cookie', 'old', 'e.com')?->hasSource('crawl'), 'the crawl source was removed by a partial crawl');
        $this->assertTrue($this->store->get('cookie', 'both', 'e.com')?->hasSource('crawl'));
    }

    public function testACompleteCrawlMarksUnseenCrawlFindingsGoneAndKeepsBeaconBackedOnes(): void
    {
        $run = $this->crawl(['https://e.com/', 'https://e.com/a'], ['https://e.com/' => self::ok(), 'https://e.com/a' => self::ok()]);

        $this->assertSame(ScanRun::COMPLETE, $run->getStatus());
        $this->assertSame(ScanFinding::GONE, $this->store->get('cookie', 'old', 'e.com')?->getStatus());
        $this->assertSame(ScanFinding::UNCLASSIFIED, $this->store->get('cookie', 'both', 'e.com')?->getStatus());
        $this->assertFalse($this->store->get('cookie', 'both', 'e.com')->hasSource('crawl'));
        $this->assertSame(ScanFinding::UNCLASSIFIED, $this->store->get('cookie', 'runtime', 'e.com')?->getStatus());
        $this->assertNotNull($this->store->get('cookie', 'live', 'e.com'));
    }

    public function testATruncatedPageListLeavesEverythingUntouched(): void
    {
        $this->crawl(['https://e.com/', 'https://e.com/a'], ['https://e.com/' => self::ok(), 'https://e.com/a' => self::ok()], (new Settings())->setScanMaxPages(1));

        $this->assertNothingGone();
    }

    public function testExcludedPagesLeaveEverythingUntouched(): void
    {
        $this->crawl(['https://e.com/', 'https://e.com/members'], ['https://e.com/' => self::ok(), 'https://e.com/members' => self::ok()], (new Settings())->setScanExcludesText('/members'));

        $this->assertNothingGone();
    }

    public function testAnOffHostUrlInThePageListLeavesEverythingUntouchedAndIsNeverFetched(): void
    {
        $fetcher = new FakeFetcher(['https://e.com/' => self::ok(), 'https://evil.example.net/' => self::ok('evilcookie')]);
        $scanner = new CrawlScanner($fetcher);

        $this->crawl(['https://e.com/', 'https://evil.example.net/'], [], null, null, $scanner);

        $this->assertSame(['https://e.com/'], $fetcher->requested);
        $this->assertNull($this->store->get('cookie', 'evilcookie', 'e.com'));
        $this->assertNothingGone();
    }

    public function testAPageThatErrorsLeavesEverythingUntouched(): void
    {
        $this->crawl(['https://e.com/', 'https://e.com/a'], ['https://e.com/' => self::ok(), 'https://e.com/a' => FakeFetcher::page('', [], 500)]);

        $this->assertNothingGone();
    }

    public function testARedirectLeavesEverythingUntouched(): void
    {
        $this->crawl(['https://e.com/', 'https://e.com/a'], ['https://e.com/' => self::ok(), 'https://e.com/a' => new FetchResult(301, ['location' => ['https://e.com/b']], '')]);

        $this->assertNothingGone();
    }

    public function testATransportFailureLeavesEverythingUntouched(): void
    {
        $this->crawl(['https://e.com/', 'https://e.com/a'], ['https://e.com/' => self::ok(), 'https://e.com/a' => new FetchFailed('timeout')]);

        $this->assertNothingGone();
    }

    public function testARefusedUrlLeavesEverythingUntouched(): void
    {
        $this->crawl(['https://e.com/', 'https://e.com/a'], ['https://e.com/' => self::ok(), 'https://e.com/a' => new UnsafeUrl('refused')]);

        $this->assertNothingGone();
    }

    public function testAnExhaustedTimeBudgetLeavesEverythingUntouched(): void
    {
        $ticks = [0, 0];
        $clock = static function () use (&$ticks): int {
            return array_shift($ticks) ?? 100000;
        };

        $run = $this->crawl(['https://e.com/', 'https://e.com/a'], ['https://e.com/' => self::ok(), 'https://e.com/a' => self::ok()], null, $clock);

        $this->assertNotNull($this->store->get('cookie', 'live', 'e.com'));
        $this->assertSame(ScanRun::COMPLETE, $run->getStatus());
        $this->assertNothingGone();
    }

    public function testAnEmptyPageListLeavesEverythingUntouched(): void
    {
        $this->crawl([], []);

        $this->assertNothingGone();
    }

    public function testAPageListWhereEveryUrlWasDroppedLeavesEverythingUntouched(): void
    {
        $this->crawl(['https://evil.example.net/'], []);

        $this->assertNothingGone();
    }

    public function testAScannerThatClaimsCompleteButScannedFewerPagesIsNotTrusted(): void
    {
        $scanner = new class implements Scanner {
            public function scan(ScanContext $context): ScanResult
            {
                return new ScanResult([], 1, 0, true);
            }
        };

        $this->crawl(['https://e.com/', 'https://e.com/a'], [], null, null, $scanner);

        $this->assertNothingGone();
    }

    public function testAScannerThatClaimsCompleteWithZeroPagesIsNotTrusted(): void
    {
        $scanner = new class implements Scanner {
            public function scan(ScanContext $context): ScanResult
            {
                return new ScanResult([], 0, 0, true);
            }
        };

        $this->crawl([], [], null, null, $scanner);

        $this->assertNothingGone();
    }

    public function testAScannerThatReportsItselfIncompleteIsHonouredEvenIfEveryPageCountsAsScanned(): void
    {
        $scanner = new class implements Scanner {
            public function scan(ScanContext $context): ScanResult
            {
                return new ScanResult([], count($context->urls), 0, false);
            }
        };

        $this->crawl(['https://e.com/', 'https://e.com/a'], [], null, null, $scanner);

        $this->assertNothingGone();
    }

    protected function setUp(): void
    {
        $this->store = new InMemoryFindingStore();
        // Seen by an earlier crawl, no longer set by the page.
        $this->store->add((new ScanFinding())->identify('cookie', 'old', 'e.com')->addSource('crawl')->setStatus(ScanFinding::UNCLASSIFIED));
        // Also reported by the browser scan, so a crawl alone can never make it gone.
        $this->store->add((new ScanFinding())->identify('cookie', 'both', 'e.com')->addSource('crawl')->addSource('beacon')->setStatus(ScanFinding::UNCLASSIFIED));
        // Seen only by the browser scan.
        $this->store->add((new ScanFinding())->identify('cookie', 'runtime', 'e.com')->addSource('beacon')->setStatus(ScanFinding::UNCLASSIFIED));
    }
}
