<?php

namespace CookieManagerKit\Scan;

final readonly class IngestSummary
{
    /**
     * @param list<array{kind: string, name: string, host: string}> $newItems at most 20, for alert emails
     */
    public function __construct(
        public int $new,
        public int $changed,
        public int $unclassified,
        public array $newItems,
    ) {}
}
