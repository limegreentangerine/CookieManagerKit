<?php

namespace CookieManagerKit\Scan;

/**
 * What a scanner is told: the site's own host and, for a crawl, the pages to fetch or, for the beacon, the report.
 */
final readonly class ScanContext
{
    /**
     * @param list<string> $urls
     */
    public function __construct(
        public string $siteHost,
        public array $urls = [],
        public ?BeaconReport $report = null,
    ) {}
}
