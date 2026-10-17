<?php

namespace CookieManagerKit\Consent;

/**
 * Persistence port for the audit log. Always returns a consent ID: when the choice names a subject that does not
 * exist (never created, or purged by retention) a new one is created.
 */
interface ConsentLogStore
{
    public function append(ConsentChoice $choice, \DateTimeImmutable $now): string;
}
