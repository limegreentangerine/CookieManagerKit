<?php

namespace CookieManagerKit\Support;

/**
 * Allowlists for everything an admin can choose about the banner's look. Used when saving and again when the
 * config is built, so nothing unvalidated reaches an inline style or a data attribute.
 */
final class StyleRules
{
    public const POSITIONS = ['bottom-left', 'bottom-right', 'bottom-bar', 'center-modal'];
    public const LAYOUTS = ['stacked', 'inline'];
    public const FONTS = ['inherit', 'system'];

    public const DEFAULT_TOKENS = [
        'bg' => '#ffffff',
        'fg' => '#111111',
        'accent' => '#0b5fff',
        'buttonBg' => '#0b5fff',
        'buttonFg' => '#ffffff',
    ];

    public const MIN_LIFETIME_DAYS = 30;
    public const MAX_LIFETIME_DAYS = 395;
    public const DEFAULT_LIFETIME_DAYS = 180;

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}\z/', $value) === 1;
    }

    public static function hex(mixed $value, string $default): string
    {
        return self::isHex($value) ? strtolower($value) : $default;
    }

    /**
     * @param list<string> $allowed
     */
    public static function choice(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    public static function radius(mixed $value): int
    {
        return is_numeric($value) ? max(0, min(32, (int) $value)) : 8;
    }

    public static function lifetime(mixed $value): int
    {
        if (!is_numeric($value)) {
            return self::DEFAULT_LIFETIME_DAYS;
        }

        return max(self::MIN_LIFETIME_DAYS, min(self::MAX_LIFETIME_DAYS, (int) $value));
    }

    /**
     * Whether a submitted lifetime is a whole number of days within the allowed range (the setter still clamps).
     */
    public static function isValidLifetime(mixed $value): bool
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d+\z/', trim($value)) === 1)) {
            return (int) $value >= self::MIN_LIFETIME_DAYS && (int) $value <= self::MAX_LIFETIME_DAYS;
        }

        return false;
    }
}
