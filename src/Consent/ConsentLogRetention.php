<?php

namespace CookieManagerKit\Consent;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;

/**
 * Removes browsers whose last decision is older than the retention period. A subject's entries go with it (the
 * foreign key cascades). Batched so a large backlog cannot hold a table lock for long; every value is bound.
 */
final class ConsentLogRetention
{
    public function __construct(private Connection $connection) {}

    public static function cutoff(int $months, \DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('-' . max(1, $months) . ' months');
    }

    public function purge(\DateTimeImmutable $cutoff, int $batch = 1000, int $maxBatches = 50): int
    {
        $removed = 0;
        $batch = max(1, $batch);

        try {
            for ($i = 0; $i < $maxBatches; $i++) {
                $count = (int) $this->connection->executeStatement(
                    'DELETE FROM cmkConsentSubjects WHERE date_updated < ? LIMIT ' . $batch,
                    [$cutoff->format('Y-m-d H:i:s')],
                );
                $removed += $count;

                if ($count < $batch) {
                    break;
                }
            }
        } catch (TableNotFoundException) {
        }

        return $removed;
    }
}
