<?php

namespace CookieManagerKit\Support;

/**
 * Turns stored counter rows into the figures the Statistics page shows. Pure: rows in, arrays out, nothing is
 * stored. "Choices" are first choices only (`changed` is kept apart) so the shares add up to 100% of the visitors
 * who decided.
 */
final class StatsReport
{
    /**
     * @return array<string, int>
     */
    public static function blank(): array
    {
        return array_fill_keys(StatsEvent::EVENTS, 0);
    }

    /**
     * @param array<string, int> $totals
     */
    public static function choices(array $totals): int
    {
        return (int) ($totals['accept_all'] ?? 0) + (int) ($totals['reject_all'] ?? 0) + (int) ($totals['custom'] ?? 0);
    }

    public static function percent(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    /**
     * @param list<array{day: string, locale: string, version: int, metric: string, total: int}> $rows
     *
     * @return array{
     *     totals: array<string, int>,
     *     choices: int,
     *     shares: array{accept_all: ?float, reject_all: ?float, custom: ?float},
     *     responseRate: ?float,
     *     days: array<string, array<string, int>>,
     *     locales: array<string, array<string, int>>,
     *     versions: array<int, array<string, int>>
     * }
     */
    public static function summarise(array $rows): array
    {
        $totals = self::blank();
        $days = [];
        $locales = [];
        $versions = [];

        foreach ($rows as $row) {
            $metric = $row['metric'];

            if (!in_array($metric, StatsEvent::EVENTS, true)) {
                continue;
            }

            $count = max(0, (int) $row['total']);

            $totals[$metric] += $count;
            $days[$row['day']] ??= self::blank();
            $days[$row['day']][$metric] += $count;
            $locales[$row['locale']] ??= self::blank();
            $locales[$row['locale']][$metric] += $count;
            $versions[$row['version']] ??= self::blank();
            $versions[$row['version']][$metric] += $count;
        }

        ksort($days);
        ksort($locales);
        ksort($versions, SORT_NUMERIC);

        $choices = self::choices($totals);

        return [
            'totals' => $totals,
            'choices' => $choices,
            'shares' => [
                'accept_all' => self::percent($totals['accept_all'], $choices),
                'reject_all' => self::percent($totals['reject_all'], $choices),
                'custom' => self::percent($totals['custom'], $choices),
            ],
            'responseRate' => self::percent($choices, $totals['shown']),
            'days' => $days,
            'locales' => $locales,
            'versions' => $versions,
        ];
    }
}
