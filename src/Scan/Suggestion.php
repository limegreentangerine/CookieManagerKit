<?php

namespace CookieManagerKit\Scan;

/**
 * A suggested category and vendor. Only ever a suggestion: an admin approves it before it reaches visitors.
 */
final readonly class Suggestion
{
    public function __construct(
        public string $category,
        public string $vendor,
    ) {}
}
