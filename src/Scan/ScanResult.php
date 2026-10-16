<?php

namespace CookieManagerKit\Scan;

final readonly class ScanResult
{
    /**
     * @param list<Finding> $findings
     * @param bool          $complete false when the scanner knows it did not see everything (nothing scanned, time budget exhausted)
     */
    public function __construct(
        public array $findings,
        public int $pagesScanned,
        public int $pagesFailed,
        public bool $complete = true,
    ) {}

    /**
     * Only a complete result may mark unseen findings as gone. Redirects, 4xx/5xx, timeouts, refused URLs, an
     * exhausted time budget or a truncated page list all make a crawl incomplete.
     */
    public function isComplete(): bool
    {
        return $this->pagesFailed === 0 && $this->complete;
    }
}
