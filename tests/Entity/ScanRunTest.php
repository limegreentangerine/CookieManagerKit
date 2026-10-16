<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\ScanRun;

final class ScanRunTest extends TestCase
{
    public function testTableName(): void
    {
        $this->assertStringContainsString('name="cmkScanRuns"', (string) (new \ReflectionClass(ScanRun::class))->getDocComment());
    }

    public function testStartAndFinish(): void
    {
        $run = (new ScanRun())->start(ScanRun::CRAWL, 1000);

        $this->assertSame(ScanRun::CRAWL, $run->getSource());
        $this->assertSame(ScanRun::RUNNING, $run->getStatus());
        $this->assertSame(1000, $run->getStartedAt());
        $this->assertNull($run->getFinishedAt());

        $run->finish(ScanRun::COMPLETE, 1060)->setCounts(3, 1, 2)->setPagesScanned(12);

        $this->assertSame(ScanRun::COMPLETE, $run->getStatus());
        $this->assertSame(1060, $run->getFinishedAt());
        $this->assertSame([3, 1, 2, 12], [$run->getNewCount(), $run->getChangedCount(), $run->getUnclassifiedCount(), $run->getPagesScanned()]);
    }

    public function testUnknownSourceAndStatusFallBack(): void
    {
        $run = (new ScanRun())->start('evil', 1);
        $this->assertSame(ScanRun::CRAWL, $run->getSource());

        $run->finish('weird', 2);
        $this->assertSame(ScanRun::FAILED, $run->getStatus());
    }

    public function testCountsNeverGoNegative(): void
    {
        $run = (new ScanRun())->setCounts(-5, -1, -9)->setPagesScanned(-3);

        $this->assertSame([0, 0, 0, 0], [$run->getNewCount(), $run->getChangedCount(), $run->getUnclassifiedCount(), $run->getPagesScanned()]);
    }
}
