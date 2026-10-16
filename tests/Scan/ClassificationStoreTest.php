<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Driver\DriverException;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\MockObject\MockObject;
use CookieManagerKit\Scan\ClassificationStore;
use CookieManagerKit\Entity\ClassificationEntry;
use Doctrine\DBAL\Exception\TableNotFoundException;

final class ClassificationStoreTest extends TestCase
{
    private function manager(): EntityManagerInterface&MockObject
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('transactional')->willReturnCallback(static fn(callable $work): mixed => $work());

        return $em;
    }

    public function testEntriesAreReturnedAsPlainArrays(): void
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findAll')->willReturn([
            (new ClassificationEntry())->define('cookie', '_ga', 'analytics', 'Google', 'd', '2 years', ClassificationEntry::BUNDLED),
        ]);
        $em = $this->manager();
        $em->method('getRepository')->with(ClassificationEntry::class)->willReturn($repository);

        $this->assertSame(
            [['kind' => 'cookie', 'pattern' => '_ga', 'category' => 'analytics', 'vendor' => 'Google', 'origin' => 'bundled']],
            (new ClassificationStore($em))->entries(),
        );
    }

    public function testBeforeTheUpgradeHasCreatedTheTableThereAreNoEntries(): void
    {
        $driver = new class('no such table') extends \Exception implements DriverException {
            public function getErrorCode(): int
            {
                return 1146;
            }

            public function getSQLState(): string
            {
                return '42S02';
            }
        };
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findAll')->willThrowException(new TableNotFoundException('no such table', $driver));
        $em = $this->manager();
        $em->method('getRepository')->willReturn($repository);

        $this->assertSame([], (new ClassificationStore($em))->entries());
    }

    public function testReplaceOriginDeletesThatOriginAndStoresTheRowsInOneTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('executeStatement')->with($this->stringContains('DELETE FROM cmkClassificationEntries WHERE origin = ?'), ['refreshed']);
        $em = $this->manager();
        $em->method('getConnection')->willReturn($connection);
        $persisted = [];
        $em->expects($this->exactly(2))->method('persist')->willReturnCallback(function (ClassificationEntry $entry) use (&$persisted): void {
            $persisted[] = $entry;
        });
        $em->expects($this->once())->method('flush');

        (new ClassificationStore($em))->replaceOrigin('refreshed', [
            ['kind' => 'cookie', 'pattern' => 'a', 'category' => 'analytics', 'vendor' => 'V', 'description' => 'd', 'duration' => '1'],
            ['kind' => 'host', 'pattern' => 'b.example.com', 'category' => 'marketing', 'vendor' => 'W', 'description' => '', 'duration' => ''],
        ]);

        $this->assertSame(['refreshed', 'refreshed'], array_map(static fn(ClassificationEntry $e): string => $e->getOrigin(), $persisted));
        $this->assertSame(['a', 'b.example.com'], array_map(static fn(ClassificationEntry $e): string => $e->getPattern(), $persisted));
    }

    public function testSyncBundledLoadsTheShippedBaseline(): void
    {
        $em = $this->manager();
        $em->method('getConnection')->willReturn($this->createMock(Connection::class));
        $count = 0;
        $em->method('persist')->willReturnCallback(static function () use (&$count): void {
            $count++;
        });

        (new ClassificationStore($em))->syncBundled();

        $this->assertGreaterThan(60, $count);
    }

    public function testSyncBundledRefusesAnInvalidFileAndTouchesNothing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cmk');
        file_put_contents($path, "not,the,right,header\n");
        $em = $this->manager();
        $em->expects($this->never())->method('getConnection');
        $em->expects($this->never())->method('persist');

        try {
            (new ClassificationStore($em))->syncBundled($path);
            $this->fail('accepted an invalid file');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        } finally {
            unlink($path);
        }
    }

    public function testAnOverrideIsStoredWithTheOverrideOrigin(): void
    {
        $em = $this->manager();
        $stored = null;
        $em->method('persist')->willReturnCallback(function (ClassificationEntry $entry) use (&$stored): void {
            $stored = $entry;
        });
        $em->expects($this->once())->method('flush');

        (new ClassificationStore($em))->addOverride('cookie', 'my_cookie', 'preferences', 'Us');

        $this->assertSame([ClassificationEntry::OVERRIDE, 'my_cookie', 'preferences'], [$stored?->getOrigin(), $stored?->getPattern(), $stored?->getCategoryHandle()]);
    }
}
