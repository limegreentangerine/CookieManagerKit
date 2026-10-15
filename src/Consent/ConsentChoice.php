<?php

namespace CookieManagerKit\Consent;

/**
 * A validated consent decision, handed to a ConsentRecorder.
 */
final class ConsentChoice
{
    /**
     * @param list<string> $categories
     */
    public function __construct(
        public readonly int $policyVersion,
        public readonly array $categories,
    ) {}
}
