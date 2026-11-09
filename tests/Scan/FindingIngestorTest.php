<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Finding;
use CookieManagerKit\Scan\Classifier;
use CookieManagerKit\Entity\ScanFinding;
use CookieManagerKit\Scan\FindingIngestor;

final class FindingIngestorTest extends TestCase
{
    private InMemoryFindingStore $store;

    private function found(string $kind, string $name, string $host): ScanFinding
    {
        $finding = $this->store->get($kind, $name, $host);

        $this->assertNotNull($finding, 'finding ' . $kind . ' ' . $name . ' was not stored');

        return $finding;
    }

    /**
     * @param list<array{kind: string, pattern: string, category: string, vendor: string, origin: string}> $entries
     */
    private function ingestor(array $entries = []): FindingIngestor
    {
        return new FindingIngestor($this->store, new Classifier($entries, static fn(string $name): bool => false));
    }

    private static function ga(): array
    {
        return [['kind' => 'cookie', 'pattern' => '_ga', 'category' => 'analytics', 'vendor' => 'Google', 'origin' => 'bundled']];
    }

    private static function cookie(string $name, string $page = 'https://e.com/'): Finding
    {
        return new Finding('cookie', $name, 'e.com', $page);
    }

    public function testANewMatchedFindingIsStoredWithItsSuggestion(): void
    {
        $summary = $this->ingestor(self::ga())->ingest([self::cookie('_ga')], 'crawl', 100, false);

        $finding = $this->store->get('cookie', '_ga', 'e.com');

        $this->assertNotNull($finding);
        $this->assertSame(ScanFinding::MATCHED, $finding->getStatus());
        $this->assertSame(['analytics', 'Google'], [$finding->getSuggestedCategory(), $finding->getSuggestedVendor()]);
        $this->assertSame([100, 100], [$finding->getFirstSeen(), $finding->getLastSeen()]);
        $this->assertSame(['crawl'], $finding->sources());
        $this->assertSame('https://e.com/', $finding->getExamplePage());
        $this->assertSame(1, $summary->new);
        $this->assertSame(0, $summary->unclassified);
        $this->assertSame([['kind' => 'cookie', 'name' => '_ga', 'host' => 'e.com']], $summary->newItems);
    }

    public function testAnUnknownFindingIsUnclassifiedAndCounted(): void
    {
        $summary = $this->ingestor()->ingest([self::cookie('mystery')], 'crawl', 100, false);

        $this->assertSame(ScanFinding::UNCLASSIFIED, $this->found('cookie', 'mystery', 'e.com')->getStatus());
        $this->assertSame(1, $summary->unclassified);
    }

    public function testTheSameFindingTwiceInOneBatchIsOneFinding(): void
    {
        $summary = $this->ingestor()->ingest([self::cookie('a'), self::cookie('a')], 'crawl', 100, false);

        $this->assertCount(1, $this->store->findings);
        $this->assertSame(1, $summary->new);
    }

    public function testASecondRunUpdatesLastSeenAndKeepsFirstSeenAndTheExamplePage(): void
    {
        $ingestor = $this->ingestor();
        $ingestor->ingest([self::cookie('a', 'https://e.com/one')], 'crawl', 100, false);
        $summary = $ingestor->ingest([self::cookie('a', 'https://e.com/two')], 'crawl', 200, false);

        $finding = $this->store->get('cookie', 'a', 'e.com');

        $this->assertSame([100, 200], [$finding?->getFirstSeen(), $finding?->getLastSeen()]);
        $this->assertSame('https://e.com/one', $finding?->getExamplePage());
        $this->assertSame(0, $summary->new);
    }

    public function testSourcesAccumulateAcrossScanners(): void
    {
        $ingestor = $this->ingestor();
        $ingestor->ingest([self::cookie('a')], 'crawl', 100, false);
        $ingestor->ingest([self::cookie('a')], 'beacon', 200, false);

        $this->assertSame(['crawl', 'beacon'], $this->found('cookie', 'a', 'e.com')->sources());
    }

    public function testACompleteCrawlMarksUnseenCrawlFindingsGoneAndCountsThem(): void
    {
        $ingestor = $this->ingestor();
        $ingestor->ingest([self::cookie('keep'), self::cookie('lose')], 'crawl', 100, false);

        $summary = $ingestor->ingest([self::cookie('keep')], 'crawl', 200, true);

        $this->assertSame(ScanFinding::GONE, $this->found('cookie', 'lose', 'e.com')->getStatus());
        $this->assertSame(ScanFinding::UNCLASSIFIED, $this->found('cookie', 'keep', 'e.com')->getStatus());
        $this->assertSame(1, $summary->changed);
    }

    public function testAnIncompleteCrawlNeverMarksAnythingGone(): void
    {
        $ingestor = $this->ingestor();
        $ingestor->ingest([self::cookie('a')], 'crawl', 100, false);

        $summary = $ingestor->ingest([], 'crawl', 200, false);

        $this->assertSame(ScanFinding::UNCLASSIFIED, $this->found('cookie', 'a', 'e.com')->getStatus());
        $this->assertSame(0, $summary->changed);
    }

    public function testABeaconRunNeverMarksAnythingGoneEvenWhenAskedTo(): void
    {
        $ingestor = $this->ingestor();
        $ingestor->ingest([self::cookie('a')], 'crawl', 100, false);

        $ingestor->ingest([], 'beacon', 200, true);

        $this->assertSame(ScanFinding::UNCLASSIFIED, $this->found('cookie', 'a', 'e.com')->getStatus());
    }

    public function testBeaconOnlyFindingsSurviveACompleteCrawl(): void
    {
        $ingestor = $this->ingestor();
        $ingestor->ingest([self::cookie('runtime')], 'beacon', 100, false);

        $ingestor->ingest([], 'crawl', 200, true);

        $this->assertSame(ScanFinding::UNCLASSIFIED, $this->found('cookie', 'runtime', 'e.com')->getStatus());
    }

    public function testAFindingSeenByBothLosesOnlyTheCrawlSourceWhenTheCrawlStopsSeeingIt(): void
    {
        $ingestor = $this->ingestor();
        $ingestor->ingest([self::cookie('both')], 'crawl', 100, false);
        $ingestor->ingest([self::cookie('both')], 'beacon', 150, false);

        $summary = $ingestor->ingest([], 'crawl', 200, true);

        $finding = $this->found('cookie', 'both', 'e.com');

        $this->assertSame(['beacon'], $finding->sources());
        $this->assertNotSame(ScanFinding::GONE, $finding->getStatus());
        $this->assertSame(0, $summary->changed);
    }

    public function testAGoneFindingThatReturnsGoesBackToItsPreviousStatusAndCountsAsChanged(): void
    {
        $ingestor = $this->ingestor();
        $ingestor->ingest([self::cookie('a')], 'crawl', 100, false);
        $this->found('cookie', 'a', 'e.com')->setStatus(ScanFinding::APPROVED);
        $ingestor->ingest([], 'crawl', 200, true);

        $summary = $ingestor->ingest([self::cookie('a')], 'crawl', 300, false);

        $this->assertSame(ScanFinding::APPROVED, $this->found('cookie', 'a', 'e.com')->getStatus());
        $this->assertSame(1, $summary->changed);
        $this->assertSame(0, $summary->new);
    }

    public function testApprovedAndIgnoredFindingsAreNeverReclassified(): void
    {
        $this->ingestor()->ingest([self::cookie('_ga'), self::cookie('_gb')], 'crawl', 100, false);
        $this->found('cookie', '_ga', 'e.com')->setStatus(ScanFinding::APPROVED);
        $this->found('cookie', '_gb', 'e.com')->setStatus(ScanFinding::IGNORED);

        $this->ingestor(self::ga())->ingest([self::cookie('_ga'), self::cookie('_gb')], 'crawl', 200, false);

        $this->assertSame(ScanFinding::APPROVED, $this->found('cookie', '_ga', 'e.com')->getStatus());
        $this->assertNull($this->found('cookie', '_ga', 'e.com')->getSuggestedCategory());
        $this->assertSame(ScanFinding::IGNORED, $this->found('cookie', '_gb', 'e.com')->getStatus());
    }

    public function testAnUnreviewedFindingIsReclassifiedWhenBetterDataArrives(): void
    {
        $this->ingestor()->ingest([self::cookie('_ga')], 'crawl', 100, false);
        $this->assertSame(ScanFinding::UNCLASSIFIED, $this->found('cookie', '_ga', 'e.com')->getStatus());

        $summary = $this->ingestor(self::ga())->ingest([self::cookie('_ga')], 'crawl', 200, false);

        $this->assertSame(ScanFinding::MATCHED, $this->found('cookie', '_ga', 'e.com')->getStatus());
        $this->assertSame('analytics', $this->found('cookie', '_ga', 'e.com')->getSuggestedCategory());
        $this->assertSame(0, $summary->unclassified);
    }

    public function testHostileNamesAreStoredVerbatim(): void
    {
        $name = '"><img src=x onerror=alert(1)>';

        $this->ingestor()->ingest([self::cookie($name)], 'crawl', 100, false);

        $this->assertSame($name, $this->found('cookie', $name, 'e.com')->getName());
    }

    public function testTheNewItemsListIsCapped(): void
    {
        $found = [];

        for ($i = 0; $i < 50; $i++) {
            $found[] = self::cookie('c' . $i);
        }

        $summary = $this->ingestor()->ingest($found, 'crawl', 100, false);

        $this->assertSame(50, $summary->new);
        $this->assertCount(20, $summary->newItems);
    }

    public function testEverythingIsFlushedOnce(): void
    {
        $this->ingestor()->ingest([self::cookie('a'), self::cookie('b')], 'crawl', 100, false);

        $this->assertSame(1, $this->store->flushes);
    }

    public function testAnOverlongNameIsCappedOnceAndDedupedAcrossRuns(): void
    {
        $name = str_repeat('x', 300);
        $ingestor = $this->ingestor();

        $ingestor->ingest([self::cookie($name)], 'crawl', 100, false);
        $summary = $ingestor->ingest([self::cookie($name)], 'crawl', 200, true);

        $this->assertCount(1, $this->store->findings);
        $this->assertSame(0, $summary->new);
        $this->assertSame(0, $summary->changed);
        $this->assertSame(ScanFinding::UNCLASSIFIED, array_values($this->store->findings)[0]->getStatus());
    }

    public function testAnIncompleteCrawlLeavesEveryFindingAloneEvenWithManyMissing(): void
    {
        $ingestor = $this->ingestor();
        $ingestor->ingest([self::cookie('a'), self::cookie('b'), self::cookie('c')], 'crawl', 100, false);

        $summary = $ingestor->ingest([self::cookie('a')], 'crawl', 200, false);

        foreach (['a', 'b', 'c'] as $name) {
            $this->assertNotSame(ScanFinding::GONE, $this->found('cookie', $name, 'e.com')->getStatus());
            $this->assertTrue($this->found('cookie', $name, 'e.com')->hasSource('crawl'));
        }

        $this->assertSame(0, $summary->changed);
    }

    public function testARunThatMarksNothingGoneNeverLoadsEveryFinding(): void
    {
        $ingestor = $this->ingestor();
        $ingestor->ingest([self::cookie('a')], 'crawl', 100, true);
        $this->store->allCalls = 0;

        $ingestor->ingest([self::cookie('a'), self::cookie('b')], 'beacon', 200, false);
        $ingestor->ingest([self::cookie('a')], 'crawl', 300, false);

        $this->assertSame(0, $this->store->allCalls);
        $this->assertSame(['crawl', 'beacon'], $this->found('cookie', 'a', 'e.com')->sources());
    }

    public function testAnUnknownSourceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->ingestor()->ingest([self::cookie('a')], 'import', 100, false);
    }

    public function testAMatchedFindingFallsBackToUnclassifiedWhenItsClassificationDisappears(): void
    {
        $this->ingestor(self::ga())->ingest([self::cookie('_ga'), self::cookie('_gb')], 'crawl', 100, false);
        $this->found('cookie', '_gb', 'e.com')->setStatus(ScanFinding::APPROVED);

        $this->ingestor()->ingest([self::cookie('_ga'), self::cookie('_gb')], 'crawl', 200, false);

        $matched = $this->found('cookie', '_ga', 'e.com');

        $this->assertSame(ScanFinding::UNCLASSIFIED, $matched->getStatus());
        $this->assertNull($matched->getSuggestedCategory());
        $this->assertSame(ScanFinding::APPROVED, $this->found('cookie', '_gb', 'e.com')->getStatus());
    }

    protected function setUp(): void
    {
        $this->store = new InMemoryFindingStore();
    }
}
