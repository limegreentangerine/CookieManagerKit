<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanFinding;

/**
 * The only thing the ingestor needs from storage, so its rules can be tested without a database.
 */
interface FindingStore
{
    /**
     * @return array<string, ScanFinding> every stored finding, keyed by fingerprint
     */
    public function all(): array;

    public function add(ScanFinding $finding): void;

    public function flush(): void;
}
