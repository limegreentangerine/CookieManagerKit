<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanFinding;

/**
 * Turns one beacon report into findings. Cookies and storage keys belong to the host the page was served from (the
 * server knows that; the browser is not asked); resource hosts are third-party loads, and the site's own host is skipped.
 */
final class BeaconScanner implements Scanner
{
    public function scan(ScanContext $context): ScanResult
    {
        $report = $context->report;

        if ($report === null) {
            return new ScanResult([], 0, 0);
        }

        $found = [];

        foreach ($report->cookies as $name) {
            $found[] = new Finding(ScanFinding::COOKIE, $name, $context->siteHost, $report->path);
        }

        foreach ($report->storage as $name) {
            $found[] = new Finding(ScanFinding::STORAGE, $name, $context->siteHost, $report->path);
        }

        foreach ($report->hosts as $host) {
            if (!Host::sameSite($host, $context->siteHost)) {
                $found[] = new Finding(ScanFinding::RESOURCE, $host, $host, $report->path);
            }
        }

        return new ScanResult($found, 1, 0);
    }
}
