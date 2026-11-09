<?php

namespace CookieManagerKit\Support;

/**
 * Encoding for everything the package writes into HTML, attributes, URLs and script blocks.
 * Entity getters are never echoed raw.
 */
final class Output
{
    /**
     * For text content and quoted attribute values.
     */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * A raw (not HTML-encoded) URL that is safe to hand to an href: http(s) or relative. Anything else, including
     * javascript:, data: and protocol-relative URLs, becomes ''. Use for JSON/config values.
     */
    public static function safeUrl(?string $url): string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return '';
        }

        // Control characters and whitespace inside the scheme are how "java\tscript:" bypasses get through.
        $normalised = preg_replace('/[\x00-\x20]+/', '', $url);

        if ($normalised === null || $normalised === '') {
            return '';
        }

        // "//host" and "/\host" are network-path references that browsers treat as another site.
        if (preg_match('#^[/\\\\]{2}#', $normalised) === 1) {
            return '';
        }

        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $normalised, $match) === 1) {
            return in_array(strtolower($match[1]), ['http', 'https'], true) ? $url : '';
        }

        return $url;
    }

    /**
     * A URL for an href attribute in a view: encoded, or '#' when unsafe.
     */
    public static function url(?string $url): string
    {
        $safe = self::safeUrl($url);

        return $safe === '' ? '#' : self::e($safe);
    }

    /**
     * JSON that is safe inside an HTML attribute or a <script> block. Throws on invalid input rather than
     * silently emitting a broken document.
     */
    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
