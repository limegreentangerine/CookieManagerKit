<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanFinding;

/**
 * Cookie names from `Set-Cookie` response lines. Only the name and the Domain attribute are read; the value is
 * never looked at, kept or logged.
 */
final class CookieHeader
{
    /**
     * @param list<string> $attributes
     */
    private static function host(array $attributes, string $siteHost): string
    {
        foreach ($attributes as $attribute) {
            $pair = explode('=', trim($attribute), 2);

            if (count($pair) === 2 && strtolower(trim($pair[0])) === 'domain') {
                $domain = Host::normalise(ltrim(trim($pair[1]), '.'));

                // A browser refuses a Domain that is not the host or a parent of it, so anything else is not real.
                if ($domain !== null && ($domain === $siteHost || str_ends_with($siteHost, '.' . $domain))) {
                    return $domain;
                }
            }
        }

        return $siteHost;
    }
    /**
     * @param list<string> $setCookieLines
     *
     * @return list<Finding>
     */
    public static function findings(array $setCookieLines, string $siteHost, string $pageUrl): array
    {
        $found = [];

        foreach ($setCookieLines as $line) {
            $parts = explode(';', (string) $line);
            $pair = explode('=', (string) array_shift($parts), 2);

            if (count($pair) !== 2) {
                continue;
            }

            $name = Clean::name($pair[0]);

            if ($name === null) {
                continue;
            }

            $host = self::host($parts, $siteHost);
            $key = $name . "\0" . $host;

            if (!isset($found[$key])) {
                $found[$key] = new Finding(ScanFinding::COOKIE, $name, $host, $pageUrl);
            }
        }

        return array_values($found);
    }
}
