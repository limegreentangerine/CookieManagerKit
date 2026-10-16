<?php

namespace CookieManagerKit\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * Reads and writes the cmkStats counters with plain SQL. The write is one atomic upsert (never read-then-write),
 * so two visitors at the same moment cannot lose a count. Every value is bound, never concatenated.
 */
final class StatsStore
{
    /**
     * MySQL/MariaDB only (ON DUPLICATE KEY), which is what Concrete runs on. Until an admin has run the package
     * Update the table does not exist; that window is swallowed so a busy site does not flood its log, while any
     * other database error still surfaces and is logged by the caller.
     */
    public static function increment(string $day, string $locale, int $version, string $metric, ?Connection $connection = null): void
    {
        $connection ??= app(EntityManagerInterface::class)->getConnection();

        try {
            $connection->executeStatement(
                'INSERT INTO cmkStats (statDay, locale, policyVersion, metric, total) VALUES (?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE total = total + 1',
                [$day, $locale, $version, $metric],
            );
        } catch (TableNotFoundException) {
        }
    }

    /**
     * @return list<array{day: string, locale: string, version: int, metric: string, total: int}>
     */
    public static function rows(string $from, string $to): array
    {
        $found = app(EntityManagerInterface::class)->getConnection()->fetchAllAssociative(
            'SELECT statDay, locale, policyVersion, metric, total FROM cmkStats
             WHERE statDay BETWEEN ? AND ? ORDER BY statDay',
            [$from, $to],
        );
        $rows = [];

        foreach ($found as $row) {
            $rows[] = [
                'day' => (string) $row['statDay'],
                'locale' => (string) $row['locale'],
                'version' => (int) $row['policyVersion'],
                'metric' => (string) $row['metric'],
                'total' => (int) $row['total'],
            ];
        }

        return $rows;
    }
}
