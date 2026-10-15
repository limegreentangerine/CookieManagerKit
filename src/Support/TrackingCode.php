<?php

namespace CookieManagerKit\Support;

/**
 * Makes a pasted tracking snippet (Concrete's SEO tracking code) inert until its category is consented.
 * <noscript> blocks are removed: a visitor without JavaScript cannot consent, so their pixel must not load.
 */
final class TrackingCode
{
    private const ATTRIBUTE_VALUE = '(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+)';

    public static function makeInert(string $code, string $category): string
    {
        if (!Text::isHandle($category)) {
            return '';
        }

        $code = preg_replace('#<noscript\b[^>]*>.*?</noscript\s*>#is', '', $code) ?? '';

        return preg_replace_callback(
            '#<script\b([^>]*)>#i',
            static function (array $match) use ($category): string {
                $attributes = $match[1];
                $type = '';

                $attributes = preg_replace_callback(
                    '/\s+type\s*=\s*(' . self::ATTRIBUTE_VALUE . ')/i',
                    static function (array $found) use (&$type): string {
                        $type = trim($found[1], "\"'");

                        return '';
                    },
                    $attributes,
                ) ?? '';

                $attributes = preg_replace('/\s+data-cmk-[a-z-]+(?:\s*=\s*' . self::ATTRIBUTE_VALUE . ')?/i', '', $attributes) ?? '';

                $remembered = '';

                if (preg_match('#^[a-z0-9/+.\-]{1,64}\z#i', $type) === 1 && !in_array(strtolower($type), ['text/javascript', 'application/javascript'], true)) {
                    $remembered = ' data-cmk-type="' . $type . '"';
                }

                return '<script' . $attributes . ' type="text/plain" data-cmk-category="' . $category . '"' . $remembered . '>';
            },
            $code,
        ) ?? '';
    }
}
