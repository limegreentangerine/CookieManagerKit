<?php

namespace CookieManagerKit\Support;

/**
 * Keeps obvious crawlers, headless browsers and scripted clients out of the statistics. A heuristic that removes
 * noise, not a security control: anything can claim to be a browser.
 */
final class BotFilter
{
    private const MARKERS = [
        'bot', 'crawl', 'spider', 'slurp', 'headless', 'lighthouse', 'preview', 'monitor',
        'curl/', 'wget', 'python-requests', 'phantomjs',
    ];

    public static function isBot(?string $userAgent): bool
    {
        $agent = strtolower(trim((string) $userAgent));

        if ($agent === '') {
            return true;
        }

        foreach (self::MARKERS as $marker) {
            if (str_contains($agent, $marker)) {
                return true;
            }
        }

        return false;
    }
}
