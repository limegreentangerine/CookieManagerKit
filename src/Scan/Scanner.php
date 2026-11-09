<?php

namespace CookieManagerKit\Scan;

/**
 * Something that can look at the site and report findings. Phase 2 has a server-side crawl and the admin's
 * scan-mode beacon; a headless-browser scanner can implement the same interface later.
 */
interface Scanner
{
    public function scan(ScanContext $context): ScanResult;
}
