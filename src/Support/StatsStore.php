<?php

namespace CookieManagerKit\Support;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and writes the cmkStats counters with plain SQL. The write is one atomic upsert (never read-then-write),
 * so two visitors at the same moment cannot lose a count. Every value is bound, never concatenated.
 */
final class StatsStore
{
    public static function increment(string $day, string $locale, int $version, string $metric): void
    {
        app(EntityManagerInterface::class)->getConnection()->executeStatement(
            'INSERT INTO cmkStats (statDay, locale, policyVersion, metric, total) VALUES (?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE total = total + 1',
            [$day, $locale, $version, $metric],
        );
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
