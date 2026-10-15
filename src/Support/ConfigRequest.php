<?php

namespace CookieManagerKit\Support;

/**
 * Reads what the config endpoint needs from the request. The locale segment is only extracted here; it is never
 * trusted, never echoed, and is turned into a real site locale by LocaleResolver.
 */
final class ConfigRequest
{
    public static function localeFromPath(string $path): ?string
    {
        return preg_match('#/cookie-manager/config/([A-Za-z0-9_\-]{1,32})\.json\z#', $path, $match) === 1 ? $match[1] : null;
    }

    public static function matchesEtag(?string $header, string $etag): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }
}
