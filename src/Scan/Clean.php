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

        $max = max(1, $max);

        // Bound the work on hostile input: look only at the first 64KB. Stripping happens over that whole window before
        // the length cap, so a long run of strippable characters in front cannot push a real name out of view.
        $value = substr($value, 0, max(65536, 8 * $max));
        $value = mb_scrub($value, 'UTF-8');
        $value = preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}\x{00A0}\x{3000}]+/u', '', $value) ?? '';
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max, 'UTF-8');
    }
}
