<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use CookieManagerKit\Scan\FindingStore;
use CookieManagerKit\Entity\ScanFinding;

final class InMemoryFindingStore implements FindingStore
{
    /** @var array<string, ScanFinding> */
    public array $findings = [];

    public int $flushes = 0;

    public int $allCalls = 0;

    public function all(): array
    {
        $this->allCalls++;

        return $this->findings;
    }

    public function findByFingerprints(array $fingerprints): array
    {
        return array_intersect_key($this->findings, array_flip($fingerprints));
    }

    public function add(ScanFinding $finding): void
    {
        $this->findings[$finding->getFingerprint()] = $finding;
    }

    public function flush(): void
    {
        $this->flushes++;
    }

    public function get(string $kind, string $name, string $host): ?ScanFinding
    {
        return $this->findings[ScanFinding::fingerprintFor($kind, $name, $host)] ?? null;
    }
}
