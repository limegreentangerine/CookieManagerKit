<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Mapping\ClassMetadata;
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

    private function sqlite(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE cmkClassificationEntries (id INTEGER PRIMARY KEY AUTOINCREMENT, kind VARCHAR(16) NOT NULL, pattern VARCHAR(128) NOT NULL, categoryHandle VARCHAR(32) NOT NULL, vendor VARCHAR(255) NOT NULL, description VARCHAR(500) NOT NULL, duration VARCHAR(64) NOT NULL, origin VARCHAR(16) NOT NULL)');

        return $connection;
    }

    private function storeOn(Connection $connection): ClassificationStore
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getTableName')->willReturn('cmkClassificationEntries');
        $metadata->method('getColumnName')->willReturnArgument(0);
        $em = $this->manager();
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->willReturn($metadata);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        return new ClassificationStore($em);
    }

    private static function row(string $pattern): array
    {
        return ['kind' => 'cookie', 'pattern' => $pattern, 'category' => 'analytics', 'vendor' => 'V', 'description' => 'd', 'duration' => '1'];
    }

    /**
     * @return list<string>
     */
    private static function patterns(Connection $connection, string $origin): array
    {
        return array_map('strval', $connection->fetchFirstColumn('SELECT pattern FROM cmkClassificationEntries WHERE origin = ? ORDER BY pattern', [$origin]));
    }

    public function testEntriesAreReturnedAsPlainArrays(): void
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->method('findBy')->with([], ['id' => 'DESC'])->willReturn([
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
        $repository->method('findBy')->willThrowException(new TableNotFoundException('no such table', $driver));
        $em = $this->manager();
        $em->method('getRepository')->willReturn($repository);

        $this->assertSame([], (new ClassificationStore($em))->entries());
    }

    public function testReplaceOriginSwapsOnlyThatOriginAndNeverTouchesTheEntityManagerUnitOfWork(): void
    {
        $connection = $this->sqlite();
        $store = $this->storeOn($connection);
        $store->replaceOrigin('refreshed', [self::row('old')]);
        $connection->insert('cmkClassificationEntries', ['kind' => 'cookie', 'pattern' => 'mine', 'categoryHandle' => 'analytics', 'vendor' => '', 'description' => '', 'duration' => '', 'origin' => 'override']);

        $store->replaceOrigin('refreshed', [self::row('a'), self::row('b')]);

        $this->assertSame(['a', 'b'], self::patterns($connection, 'refreshed'));
        $this->assertSame(['mine'], self::patterns($connection, 'override'));
    }

    public function testAFailureMidwayKeepsThePreviousRows(): void
    {
        $connection = $this->sqlite();
        $store = $this->storeOn($connection);
        $store->replaceOrigin('refreshed', [self::row('keep')]);
        $bad = self::row('bad');
        $bad['kind'] = ['not a string'];

        try {
            $store->replaceOrigin('refreshed', [self::row('new'), $bad]);
            $this->fail('accepted a broken row');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(['keep'], self::patterns($connection, 'refreshed'));
    }

    public function testOverridesAndUnknownOriginsCanNeverBeReplaced(): void
    {
        $store = $this->storeOn($this->sqlite());

        foreach (['override', 'nonsense', ''] as $origin) {
            try {
                $store->replaceOrigin($origin, []);
                $this->fail('replaced ' . $origin);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSyncBundledLoadsTheShippedBaselineAndKeepsOtherOrigins(): void
    {
        $connection = $this->sqlite();
        $store = $this->storeOn($connection);
        $store->replaceOrigin('refreshed', [self::row('r')]);

        $store->syncBundled();

        $this->assertGreaterThan(60, count(self::patterns($connection, 'bundled')));
        $this->assertSame(['r'], self::patterns($connection, 'refreshed'));
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
        $connection = $this->sqlite();

        $this->storeOn($connection)->addOverride('cookie', 'my_cookie', 'preferences', 'Us');

        $this->assertSame([['override', 'my_cookie', 'preferences']], $connection->fetchAllNumeric('SELECT origin, pattern, categoryHandle FROM cmkClassificationEntries'));
    }

    public function testSavingTheSameOverrideAgainReplacesItAndTouchesNothingElse(): void
    {
        $connection = $this->sqlite();
        $store = $this->storeOn($connection);
        $store->replaceOrigin('bundled', [self::row('my_cookie')]);

        $store->addOverride('cookie', 'my_cookie', 'analytics', 'A');
        $store->addOverride('cookie', 'my_cookie', 'marketing', 'B');
        $store->addOverride('host', 'my_cookie', 'marketing', 'B');

        $this->assertSame(
            [['bundled', 'cookie', 'analytics'], ['override', 'cookie', 'marketing'], ['override', 'host', 'marketing']],
            $connection->fetchAllNumeric('SELECT origin, kind, categoryHandle FROM cmkClassificationEntries ORDER BY origin, kind'),
        );
    }
}
