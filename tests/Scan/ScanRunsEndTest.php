<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\ScanRuns;
use CookieManagerKit\Entity\ScanRun;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;

final class ScanRunsEndTest extends TestCase
{
    public function testEndRunningFinishesEveryRunningBeaconRunNow(): void
    {
        $a = (new ScanRun())->start(ScanRun::BEACON, 1000);
        $b = (new ScanRun())->start(ScanRun::BEACON, 1100);
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findBy')->with(['source' => ScanRun::BEACON, 'status' => ScanRun::RUNNING])->willReturn([$a, $b]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(ScanRun::class)->willReturn($repository);
        $em->expects($this->once())->method('flush');

        $this->assertSame(2, ScanRuns::endRunning($em, 1200));
        $this->assertSame([ScanRun::COMPLETE, 1200], [$a->getStatus(), $a->getFinishedAt()]);
        $this->assertSame([ScanRun::COMPLETE, 1200], [$b->getStatus(), $b->getFinishedAt()]);
    }

    public function testEndRunningWithNothingRunningDoesNotFlush(): void
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findBy')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->expects($this->never())->method('flush');

        $this->assertSame(0, ScanRuns::endRunning($em, 1200));
    }
}
