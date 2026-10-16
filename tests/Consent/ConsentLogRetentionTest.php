<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Consent;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use CookieManagerKit\Consent\ConsentLogRetention;

final class ConsentLogRetentionTest extends TestCase
{
    public function testTheCutoffIsMonthsBeforeNow(): void
    {
        $cutoff = ConsentLogRetention::cutoff(24, new \DateTimeImmutable('2026-10-16 12:00:00'));

        $this->assertSame('2024-10-16 12:00:00', $cutoff->format('Y-m-d H:i:s'));
    }

    public function testItDeletesInBatchesUntilABatchComesBackShort(): void
    {
        $connection = $this->createMock(Connection::class);
        $calls = [];
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $params) use (&$calls): int {
            $calls[] = [$sql, $params];

            return count($calls) === 1 ? 1000 : 250;
        });

        $removed = (new ConsentLogRetention($connection))->purge(new \DateTimeImmutable('2024-01-01 00:00:00'), 1000);

        $this->assertSame(1250, $removed);
        $this->assertCount(2, $calls);
        $this->assertStringContainsString('DELETE FROM cmkConsentSubjects', $calls[0][0]);
        $this->assertStringContainsString('date_updated < ?', $calls[0][0]);
        $this->assertSame(['2024-01-01 00:00:00'], $calls[0][1]);
    }

    public function testItStopsAtTheBatchLimit(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(3))->method('executeStatement')->willReturn(10);

        $this->assertSame(30, (new ConsentLogRetention($connection))->purge(new \DateTimeImmutable('2024-01-01'), 10, 3));
    }

    public function testNothingToDeleteIsZero(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(0);

        $this->assertSame(0, (new ConsentLogRetention($connection))->purge(new \DateTimeImmutable('2024-01-01')));
    }
}
