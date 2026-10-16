<?php

namespace CookieManagerKit\Scan;

/**
 * Cleans a name that came from a page or a browser (cookie name, storage key). Markup is deliberately kept: names
 * are shown only through output encoding, and rewriting them would make two different cookies look the same.
 */
final class Clean
{
    public static function name(mixed $value, int $max = 128): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = mb_scrub($value, 'UTF-8');
        $value = preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', '', $value) ?? '';
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max, 'UTF-8');
    }
}
