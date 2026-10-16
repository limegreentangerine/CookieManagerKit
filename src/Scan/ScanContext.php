<?php

namespace CookieManagerKit\Scan;

/**
 * What a scanner is told: the site's own host and, for a crawl, the pages to fetch. $truncated says the page list
 * was cut short (cap reached), so the crawl cannot have seen the whole site.
 */
final readonly class ScanContext
{
    /**
     * @param list<string> $urls
     */
    public function __construct(
        public string $siteHost,
        public array $urls = [],
        public bool $truncated = false,
    ) {}
}
