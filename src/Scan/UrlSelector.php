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
        $path = self::normalise($path);

        foreach ($excludes as $raw) {
            $pattern = self::normalise((string) $raw, true);

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
     * Brings a path (or pattern) to one canonical form so an encoded, re-cased or dotted spelling cannot slip past an
     * exclude: percent-decoded, lower-cased, ;params and query stripped, // collapsed, . and .. resolved, no trailing slash.
     */
    private static function normalise(string $path, bool $keepWildcard = false): string
    {
        $path = rawurldecode(explode('?', $path, 2)[0]);
        $path = mb_strtolower($path, 'UTF-8');
        $out = [];

        foreach (explode('/', $path) as $segment) {
            $segment = explode(';', $segment, 2)[0];

            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($out);

                continue;
            }

            $out[] = $segment;
        }

        return '/' . implode('/', $out);
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
        $dropped = [];

        foreach ($urls as $url) {
            $url = (string) $url;
            $hash = strpos($url, '#');
            $url = $hash === false ? $url : substr($url, 0, $hash);
            $path = self::path($url);

            if ($path === null || isset($kept[$url]) || isset($dropped[$url])) {
                continue;
            }

            if (self::isExcluded($path, $excludes)) {
                $excluded++;
                $dropped[$url] = true;

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
