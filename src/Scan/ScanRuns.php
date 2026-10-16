<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanRun;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lifecycle rules for scan runs. A scan-mode session lasts SESSION_SECONDS; after that its token no longer works, and
 * a run still marked `running` is closed the next time anything looks (no background job is needed).
 */
final class ScanRuns
{
    private const GRACE_SECONDS = 60;
    public const SESSION_SECONDS = 1800;

    public static function isExpired(ScanRun $run, int $now): bool
    {
        return $run->getStatus() === ScanRun::RUNNING && $now > $run->getStartedAt() + self::SESSION_SECONDS + self::GRACE_SECONDS;
    }

    public static function acceptsReports(?ScanRun $run, int $now): bool
    {
        return $run !== null
            && $run->getSource() === ScanRun::BEACON
            && $run->getStatus() === ScanRun::RUNNING
            && $now <= $run->getStartedAt() + self::SESSION_SECONDS;
    }

    public static function closeExpired(EntityManagerInterface $em, int $now): int
    {
        $closed = 0;

        foreach ($em->getRepository(ScanRun::class)->findBy(['source' => ScanRun::BEACON, 'status' => ScanRun::RUNNING]) as $run) {
            if (self::isExpired($run, $now)) {
                $run->finish(ScanRun::COMPLETE, $run->getStartedAt() + self::SESSION_SECONDS);
                $closed++;
            }
        }

        if ($closed > 0) {
            $em->flush();
        }

        return $closed;
    }

    public static function lastBeaconAt(EntityManagerInterface $em): ?int
    {
        $run = $em->getRepository(ScanRun::class)->findOneBy(['source' => ScanRun::BEACON], ['startedAt' => 'DESC']);

        return $run instanceof ScanRun ? ($run->getFinishedAt() ?? $run->getStartedAt()) : null;
    }

    /**
     * @return list<ScanRun>
     */
    public static function recent(EntityManagerInterface $em, int $limit): array
    {
        return array_values($em->getRepository(ScanRun::class)->findBy([], ['id' => 'DESC'], $limit));
    }
}
