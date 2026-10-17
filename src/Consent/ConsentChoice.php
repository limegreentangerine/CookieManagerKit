<?php

namespace CookieManagerKit\Consent;

/**
 * A validated consent decision, handed to a ConsentRecorder. `subjectId` is the consent ID the browser claims to
 * already have; it has been checked for shape only, so a recorder must not assume it exists.
 */
final class ConsentChoice
{
    /**
     * @param list<string> $categories
     */
    public function __construct(
        public readonly int $policyVersion,
        public readonly array $categories,
        public readonly string $trigger = ConsentTrigger::CUSTOM,
        public readonly string $locale = '',
        public readonly string $userAgentFamily = 'Other/unknown',
        public readonly ?string $subjectId = null,
    ) {}
}
