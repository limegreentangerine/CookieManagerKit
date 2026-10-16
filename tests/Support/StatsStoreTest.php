<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\StatsStore;
use Doctrine\DBAL\Driver\DriverException;
use Doctrine\DBAL\Exception\TableNotFoundException;

final class StatsStoreTest extends TestCase
{
    public function testBindsEveryValueAndNeverConcatenatesThem(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('ON DUPLICATE KEY UPDATE total = total + 1'), ['2026-10-16', 'en_GB', 3, 'shown']);

        StatsStore::increment('2026-10-16', 'en_GB', 3, 'shown', $connection);
    }

    public function testAMissingTableBeforeTheUpgradeHasRunIsSwallowed(): void
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
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException(new TableNotFoundException('no such table', $driver));

        StatsStore::increment('2026-10-16', 'en_GB', 3, 'shown', $connection);

        $this->addToAssertionCount(1);
    }

    public function testAnyOtherDatabaseErrorStillSurfacesSoItIsLogged(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('deadlock'));

        $this->expectException(\RuntimeException::class);

        StatsStore::increment('2026-10-16', 'en_GB', 3, 'shown', $connection);
    }
}
