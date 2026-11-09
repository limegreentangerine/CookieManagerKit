<?php

namespace CookieManagerKit\Scan;

/**
 * Which host the crawler is allowed to visit: the site's configured canonical URL, and nothing else. There is no
 * fallback to the current request's host: a Host header is chosen by whoever sent the request, and a scheduled or
 * command-line run has no trustworthy request at all. A site without a canonical URL cannot be crawled.
 */
final class CrawlTarget
{
    public const MISSING = 'Set the canonical URL in Dashboard > System & Settings > SEO & Statistics > URLs and Redirection so the scanner knows which host is yours.';

    /**
     * @throws UnsafeUrl
     */
    public static function baseUrl(): string
    {
        $site = app('site')->getSite();

        return self::pick([(string) $site->getSiteCanonicalURL(), (string) app('config')->get('concrete.seo.canonical_url')]);
    }

    /**
     * The first non-empty candidate, trimmed of whitespace and trailing slashes.
     *
     * @param list<string> $candidates
     *
     * @throws UnsafeUrl when there is none
     */
    public static function pick(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (trim($candidate) !== '') {
                return rtrim(trim($candidate), '/');
            }
        }

        throw new UnsafeUrl(self::MISSING);
    }

    /**
     * The scheme, host and port the crawl URLs are built on. The host is the guard's normalised one (lower-cased, one
     * trailing dot dropped), never the raw string from configuration.
     */
    public static function origin(string $baseUrl, UrlGuard $guard): string
    {
        $parts = parse_url($baseUrl);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $port = is_array($parts) && isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $guard->host() . $port;
    }
}
