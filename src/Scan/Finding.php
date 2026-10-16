<?php

namespace CookieManagerKit\Scan;

/**
 * What a scanner reports: one thing seen on one page. Plain data; nothing here is trusted.
 */
final readonly class Finding
{
    public function __construct(
        public string $kind,
        public string $name,
        public string $host,
        public string $page = '',
    ) {}
}
