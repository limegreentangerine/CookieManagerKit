<?php

namespace CookieManagerKit\Support;

/**
 * Plain-text normalising for admin input and format checks for identifiers. Banner text is never HTML.
 * Patterns end in \z, not $, because $ also matches before a trailing newline.
 */
final class Text
{
    public static function plain(mixed $value, int $max = 255): string
    {
        $value = trim(strip_tags(is_string($value) ? $value : ''));
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';

        return mb_substr(trim($value), 0, $max);
    }

    public static function multiline(mixed $value, int $max = 2000): string
    {
        $value = strip_tags(is_string($value) ? $value : '');
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $value) ?? '';
        $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? '';

        return mb_substr(trim($value), 0, $max);
    }

    public static function isLocale(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,8}){0,2}\z/', $value) === 1;
    }

    public static function isHandle(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z0-9_-]{1,32}\z/', $value) === 1;
    }
}
