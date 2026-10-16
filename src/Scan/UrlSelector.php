<?php

namespace CookieManagerKit\Scan;

/**
 * Chooses which pages a crawl fetches: drops duplicates and unparseable URLs, applies the admin's exclude patterns
 * (a pattern is a path, matching itself and its children, or a path ending in * for a prefix), then caps the list.
 */
final class UrlSelector
{
    private static function path(string $url): ?string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        return (string) ($parts['path'] ?? '/');
    }

    /**
     * @param list<string> $excludes
     */
    private static function isExcluded(string $path, array $excludes): bool
    {
        foreach ($excludes as $pattern) {
            if (str_contains($pattern, '*')) {
                if (fnmatch($pattern, $path)) {
                    return true;
                }
            } elseif ($path === $pattern || str_starts_with($path, rtrim($pattern, '/') . '/')) {
                return true;
            }
        }

        return false;
    }
    /**
     * @param list<string> $urls
     * @param list<string> $excludes
     *
     * @return array{urls: list<string>, truncated: bool, excluded: int}
     */
    public static function select(array $urls, array $excludes, int $cap): array
    {
        $kept = [];
        $excluded = 0;

        foreach ($urls as $url) {
            $url = (string) $url;
            $path = self::path($url);

            if ($path === null || isset($kept[$url])) {
                continue;
            }

            if (self::isExcluded($path, $excludes)) {
                $excluded++;

                continue;
            }

            $kept[$url] = true;
        }

        $all = array_map('strval', array_keys($kept));
        $cap = max(0, $cap);

        return [
            'urls' => array_slice($all, 0, $cap),
            'truncated' => count($all) > $cap,
            'excluded' => $excluded,
        ];
    }
}
