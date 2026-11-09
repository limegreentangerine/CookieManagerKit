<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\ScanRuns;
use CookieManagerKit\Entity\ScanRun;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;

final class ScanRunsTest extends TestCase
{
    private static function beacon(int $started, string $status = ScanRun::RUNNING): ScanRun
    {
        $run = (new ScanRun())->start(ScanRun::BEACON, $started);

        return $status === ScanRun::RUNNING ? $run : $run->finish($status, $started + 10);
    }

    private function emWith(array $runs): EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findBy')->willReturn($runs);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        return $em;
    }

    public function testARunningBeaconRunAcceptsReportsInsideItsSession(): void
    {
        $run = self::beacon(1000);

        $this->assertTrue(ScanRuns::acceptsReports($run, 1000));
        $this->assertTrue(ScanRuns::acceptsReports($run, 1000 + ScanRuns::SESSION_SECONDS));
        $this->assertFalse(ScanRuns::acceptsReports($run, 1000 + ScanRuns::SESSION_SECONDS + 1));
    }

    public function testAnEndedMissingOrCrawlRunNeverAcceptsReports(): void
    {
        $this->assertFalse(ScanRuns::acceptsReports(null, 1000));
        $this->assertFalse(ScanRuns::acceptsReports(self::beacon(1000, ScanRun::COMPLETE), 1001));
        $this->assertFalse(ScanRuns::acceptsReports(self::beacon(1000, ScanRun::FAILED), 1001));
        $this->assertFalse(ScanRuns::acceptsReports((new ScanRun())->start(ScanRun::CRAWL, 1000), 1001));
    }

    public function testIsExpiredNeedsRunningAndTheGracePeriod(): void
    {
        $run = self::beacon(1000);

        $this->assertFalse(ScanRuns::isExpired($run, 1000 + ScanRuns::SESSION_SECONDS + 60));
        $this->assertTrue(ScanRuns::isExpired($run, 1000 + ScanRuns::SESSION_SECONDS + 61));
        $this->assertFalse(ScanRuns::isExpired(self::beacon(1000, ScanRun::COMPLETE), 999999));
    }

    public function testCloseExpiredFinishesOnlyExpiredRunningBeaconRuns(): void
    {
        $old = self::beacon(1000);
        $fresh = self::beacon(100000);
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findBy')->with(['source' => ScanRun::BEACON, 'status' => ScanRun::RUNNING])->willReturn([$old, $fresh]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(ScanRun::class)->willReturn($repository);
        $em->expects($this->once())->method('flush');

        $closed = ScanRuns::closeExpired($em, 100001);

        $this->assertSame(1, $closed);
        $this->assertSame(ScanRun::COMPLETE, $old->getStatus());
        $this->assertSame(ScanRun::RUNNING, $fresh->getStatus());
    }

    public function testLastBeaconAtPrefersTheFinishTime(): void
    {
        $finished = self::beacon(1000, ScanRun::COMPLETE);
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->with(['source' => ScanRun::BEACON], ['startedAt' => 'DESC'])->willReturn($finished);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $this->assertSame(1010, ScanRuns::lastBeaconAt($em));
    }

    public function testLastBeaconAtIsNullWhenThereWasNeverARun(): void
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findOneBy')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $this->assertNull(ScanRuns::lastBeaconAt($em));
    }

    public function testRecentReturnsTheNewestRunsFirst(): void
    {
        $runs = [self::beacon(2), self::beacon(1)];
        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects($this->once())->method('findBy')->with([], ['id' => 'DESC'], 5)->willReturn($runs);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $this->assertSame($runs, ScanRuns::recent($em, 5));
    }

    public function testSweepFailsOnlyStaleRunningCrawls(): void
    {
        $stale = (new ScanRun())->start(ScanRun::CRAWL, 1000);
        $fresh = (new ScanRun())->start(ScanRun::CRAWL, 2000);
        $em = $this->emWith([$stale, $fresh]);
        $em->expects($this->once())->method('flush');

        $this->assertSame(1, ScanRuns::sweepStaleCrawls($em, 1000 + ScanRuns::CRAWL_STALE_SECONDS + 1));
        $this->assertSame(ScanRun::FAILED, $stale->getStatus());
        $this->assertSame(ScanRun::RUNNING, $fresh->getStatus());
    }

    public function testCrawlIsRunningOnlyForANonStaleRunningCrawl(): void
    {
        $run = (new ScanRun())->start(ScanRun::CRAWL, 1000);

        $this->assertTrue(ScanRuns::crawlIsRunning($this->emWith([$run]), 1000 + ScanRuns::CRAWL_STALE_SECONDS));
        $this->assertFalse(ScanRuns::crawlIsRunning($this->emWith([$run]), 1000 + ScanRuns::CRAWL_STALE_SECONDS + 1));
        $this->assertFalse(ScanRuns::crawlIsRunning($this->emWith([]), 1000));
    }
}
