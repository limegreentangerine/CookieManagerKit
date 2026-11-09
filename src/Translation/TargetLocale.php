<?php

namespace CookieManagerKit\Translation;

/**
 * Vets a POSTed target language: it must be one of the site's own locales and not the default.
 */
final class TargetLocale
{
    /**
     * @param array<string, string> $options site locales (handle => label)
     */
    public static function resolve(mixed $posted, array $options, string $default): ?string
    {
        if (!is_string($posted) || !array_key_exists($posted, $options) || $posted === $default) {
            return null;
        }

        return $posted;
    }
}
