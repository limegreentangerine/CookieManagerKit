<?php

namespace CookieManagerKit\Consent;

/**
 * The only values a consent log entry's trigger may take. One list, so the endpoint, the entity and the dashboard
 * filter cannot drift apart.
 */
final class ConsentTrigger
{
    public const ACCEPT_ALL = 'accept_all';
    public const REJECT_ALL = 'reject_all';
    public const CUSTOM = 'custom';
    public const GPC = 'gpc';
    public const RECONSENT = 'reconsent';
    public const ALL = [self::ACCEPT_ALL, self::REJECT_ALL, self::CUSTOM, self::GPC, self::RECONSENT];

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::ALL, true);
    }
}
