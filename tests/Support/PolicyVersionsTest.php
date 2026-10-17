<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\{Settings};
use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Support\PolicyVersions;

final class PolicyVersionsTest extends TestCase
{
    public function testBumpingIncrementsTheVersionAndRecordsWhy(): void
    {
        $settings = new Settings();
        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $o) use (&$persisted): void {
            $persisted[] = $o;
        });

        $change = PolicyVersions::bump($em, $settings, 'Added Google Analytics', 7, new \DateTimeImmutable('@1760616000'));

        $this->assertSame(2, $settings->getPolicyVersion());
        $this->assertSame(2, $change->getVersion());
        $this->assertSame('Added Google Analytics', $change->getReason());
        $this->assertSame(7, $change->getAdminUserId());
        $this->assertSame(1760616000, $change->getCreatedAt());
        $this->assertSame([$change], $persisted);
    }

    public function testAnEmptyReasonChangesNothing(): void
    {
        $settings = new Settings();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        try {
            PolicyVersions::bump($em, $settings, "  \n", 1);
            $this->fail('expected an exception');
        } catch (\InvalidArgumentException) {
            $this->assertSame(1, $settings->getPolicyVersion());
        }
    }

    public function testAnOverlongReasonIsCapped(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $change = PolicyVersions::bump($em, new Settings(), str_repeat('é', 800), 1);

        $this->assertSame(500, mb_strlen($change->getReason()));
    }
}
