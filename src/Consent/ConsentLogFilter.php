<?php

namespace CookieManagerKit\Consent;

use CookieManagerKit\Support\{StatsRange, Text};

/**
 * The filters of the consent log page, vetted. Anything that does not pass is dropped, never queried. A search text
 * that is not a GUID is kept (truncated) only so the page can show it back, encoded, in a "no results" message.
 */
final class ConsentLogFilter
{
    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $trigger,
        public readonly int $version,
        public readonly string $category,
        public readonly string $consentId,
        public readonly string $rejectedId,
    ) {}

    public static function parse(mixed $from, mixed $to, mixed $trigger, mixed $version, mixed $category, mixed $consentId, string $today): self
    {
        $range = StatsRange::parse($from, $to, $today);

        $search = is_string($consentId) ? strtolower(trim($consentId)) : '';
        $isGuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $search) === 1;

        return new self(
            $range['from'],
            $range['to'],
            ConsentTrigger::isValid($trigger) ? $trigger : '',
            is_string($version) && preg_match('/^\d{1,7}\z/', $version) === 1 ? (int) $version : 0,
            Text::isHandle($category) ? $category : '',
            $isGuid ? $search : '',
            !$isGuid && $search !== '' ? mb_substr(Text::plain($consentId, 64), 0, 64) : '',
        );
    }
}
