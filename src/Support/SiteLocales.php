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

        return $options;
    }

    public static function defaultLocale(): string
    {
        $locale = app('site')->getActiveSiteForEditing()->getDefaultLocale();

        return $locale ? $locale->getLocale() : (array_key_first(self::options()) ?? 'en_US');
    }
}
