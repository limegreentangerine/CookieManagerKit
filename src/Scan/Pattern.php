<?php

namespace CookieManagerKit\Scan;

/**
 * Matching for classification patterns. A pattern is an exact value, or a value with up to three `*` wildcards.
 * Everything except `*` is literal: patterns come from downloaded data and must never act as a regular expression.
 */
final class Pattern
{
    private const MAX_PATTERN = 128;
    private const MAX_SUBJECT = 255;
    private const MAX_WILDCARDS = 3;

    /**
     * Iterative glob match with single-star backtracking: no regular expression is ever built from the pattern, and
     * the work is bounded by pattern length times subject length.
     */
    private static function glob(string $pattern, string $subject): bool
    {
        $p = 0;
        $s = 0;
        $plen = strlen($pattern);
        $slen = strlen($subject);
        $star = -1;
        $mark = 0;

        while ($s < $slen) {
            if ($p < $plen && $pattern[$p] === '*') {
                $star = $p++;
                $mark = $s;
            } elseif ($p < $plen && $pattern[$p] === $subject[$s]) {
                $p++;
                $s++;
            } elseif ($star !== -1) {
                $p = $star + 1;
                $s = ++$mark;
            } else {
                return false;
            }
        }

        while ($p < $plen && $pattern[$p] === '*') {
            $p++;
        }

        return $p === $plen;
    }

    public static function matches(string $pattern, string $subject, bool $caseInsensitive = false): bool
    {
        if ($pattern === '' || strlen($pattern) > self::MAX_PATTERN || strlen($subject) > self::MAX_SUBJECT || substr_count($pattern, '*') > self::MAX_WILDCARDS) {
            return false;
        }

        if ($caseInsensitive) {
            $pattern = strtolower($pattern);
            $subject = strtolower($subject);
        }

        return self::glob($pattern, $subject);
    }

    /**
     * `*.example.com` matches `example.com` itself and every subdomain of it, never a lookalike such as
     * `badexample.com`.
     */
    public static function matchesHost(string $pattern, string $host): bool
    {
        if ($pattern === '' || strlen($pattern) > self::MAX_PATTERN || strlen($host) > self::MAX_SUBJECT || substr_count($pattern, '*') > self::MAX_WILDCARDS) {
            return false;
        }

        $pattern = strtolower($pattern);
        $host = strtolower($host);

        if (str_starts_with($pattern, '*.') && !str_contains(substr($pattern, 2), '*')) {
            $apex = substr($pattern, 2);

            return $apex !== '' && ($host === $apex || str_ends_with($host, '.' . $apex));
        }

        return self::matches($pattern, $host, true);
    }
}
