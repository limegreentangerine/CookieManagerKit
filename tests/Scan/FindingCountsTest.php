<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use Doctrine\ORM\EntityRepository;
use CookieManagerKit\Entity\ScanFinding;
use CookieManagerKit\Scan\FindingCounts;
use Doctrine\ORM\EntityManagerInterface;

final class FindingCountsTest extends TestCase
{
    public function testUnclassifiedIsCountedGloballyByStatus(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())->method('count')->with(['status' => ScanFinding::UNCLASSIFIED])->willReturn(7);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(ScanFinding::class)->willReturn($repository);

        $this->assertSame(7, FindingCounts::unclassified($em));
    }
}
