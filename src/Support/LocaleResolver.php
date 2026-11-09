<?php

namespace CookieManagerKit\Support;

/**
 * Turns a locale that arrived from the outside (URL path) into one the site actually has. The result is always
 * one of the site's own spellings or the default, so a hostile value is never echoed back or used as a cache key.
 */
final class LocaleResolver
{
    /**
     * @param list<string> $siteLocales
     */
    public static function resolve(string $requested, array $siteLocales, string $default): string
    {
        if (Text::isLocale($requested)) {
            foreach ($siteLocales as $locale) {
                if (strcasecmp($locale, $requested) === 0) {
                    return $locale;
                }
            }
        }

        return $default;
    }
}
