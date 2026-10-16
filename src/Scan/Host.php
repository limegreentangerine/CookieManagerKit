<?php

namespace CookieManagerKit\Scan;

/**
 * Host validation for everything a scan records. A host is a domain name or an IPv4 literal; anything else
 * (IPv6 literals, single labels, spaces, markup) is not recorded.
 */
final class Host
{
    public static function normalise(mixed $host): ?string
    {
        if (!is_string($host)) {
            return null;
        }

        $host = strtolower(rtrim(trim($host), '.'));

        if ($host === '' || strlen($host) > 253) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }

        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $host) !== 1) {
            return null;
        }

        // A numeric last label would make "1.2.3.999" look like a host; real top-level domains are never numeric.
        $last = substr($host, (int) strrpos($host, '.') + 1);

        return ctype_digit($last) ? null : $host;
    }

    /**
     * The host of an absolute or scheme-relative http(s) URL. Relative URLs belong to the page itself and give null.
     */
    public static function fromUrl(mixed $url): ?string
    {
        if (!is_string($url)) {
            return null;
        }

        $url = trim($url);

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        $parts = parse_url($url);

        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }

        return self::normalise($parts['host'] ?? null);
    }

    public static function sameSite(string $host, string $siteHost): bool
    {
        $strip = static fn(string $value): string => str_starts_with($value, 'www.') ? substr($value, 4) : $value;

        return $strip(strtolower($host)) === $strip(strtolower($siteHost));
    }
}
