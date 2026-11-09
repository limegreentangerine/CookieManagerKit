<?php

namespace CookieManagerKit\Support;

/**
 * Same-origin check for the public POST endpoint. A CSRF token cannot be used (pages are cached and anonymous),
 * so the browser-supplied Origin (or Referer when there is no Origin) must match the host being served.
 */
final class RequestGuard
{
    public static function isSameOrigin(?string $origin, ?string $referer, string $host): bool
    {
        foreach ([$origin, $referer] as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            $parts = parse_url($value);

            if (!is_array($parts) || !isset($parts['host'])) {
                return false;
            }

            $candidate = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

            return strcasecmp($candidate, $host) === 0;
        }

        return false;
    }
}
