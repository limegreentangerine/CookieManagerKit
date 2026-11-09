<?php

namespace CookieManagerKit\Support;

/**
 * Turns the Statistics page's `from` and `to` query values into a safe, inclusive date range. Pure. The values come
 * straight from the URL, so anything that is not a real `YYYY-MM-DD` date is ignored rather than trusted.
 */
final class StatsRange
{
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 366;

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * @param string $today a valid `Y-m-d` date, the last day that can be shown
     *
     * @return array{from: string, to: string}
     */
    public static function parse(mixed $from, mixed $to, string $today): array
    {
        $latest = new \DateTimeImmutable($today);
        $end = self::date($to) ?? $latest;

        if ($end > $latest) {
            $end = $latest;
        }

        $default = $end->modify('-' . (self::DEFAULT_DAYS - 1) . ' days');
        $start = self::date($from) ?? $default;

        if ($start > $end) {
            $start = $default;
        }

        $earliest = $end->modify('-' . (self::MAX_DAYS - 1) . ' days');

        if ($start < $earliest) {
            $start = $earliest;
        }

        return ['from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d')];
    }
}
