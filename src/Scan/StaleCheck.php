<?php

namespace CookieManagerKit\Scan;

/**
 * The runtime scan is manual, so the scheduled task flags it when it has not been done for a while.
 */
final class StaleCheck
{
    public static function isStale(?int $lastRuntimeScanAt, int $now, int $days): bool
    {
        return $lastRuntimeScanAt === null || $now - $lastRuntimeScanAt > $days * 86400;
    }
}
