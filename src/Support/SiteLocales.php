<?php

namespace CookieManagerKit\Support;

/**
 * The locales of the site being edited in the dashboard, for the per-language tabs.
 */
final class SiteLocales
{
    /**
     * @return array<string, string> locale ID => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (app('site')->getActiveSiteForEditing()->getLocales() as $locale) {
            $options[$locale->getLocale()] = $locale->getLanguageText() . ' (' . $locale->getLocale() . ')';
        }

        $site = app('site')->getActiveSiteForEditing();
        $default = $site ? $site->getDefaultLocale() : null;

        return self::defaultFirst($options, $default ? $default->getLocale() : '');
    }

    /**
     * Moves the default locale to the front, so the first (active) tab is never a hidden required field.
     *
     * @param array<string, string> $options locale ID => label
     *
     * @return array<string, string>
     */
    public static function defaultFirst(array $options, string $default): array
    {
        if (!array_key_exists($default, $options)) {
            return $options;
        }

        return [$default => $options[$default]] + $options;
    }

    public static function defaultLocale(): string
    {
        $locale = app('site')->getActiveSiteForEditing()->getDefaultLocale();

        return $locale ? $locale->getLocale() : (array_key_first(self::options()) ?? 'en_US');
    }
}
