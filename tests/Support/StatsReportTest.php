<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\StatsReport;

final class StatsReportTest extends TestCase
{
    /**
     * @return list<array{day: string, locale: string, version: int, metric: string, total: int}>
     */
    private static function rows(): array
    {
        return [
            ['day' => '2026-10-16', 'locale' => 'fr_FR', 'version' => 2, 'metric' => 'shown', 'total' => 5],
            ['day' => '2026-10-15', 'locale' => 'en_GB', 'version' => 1, 'metric' => 'shown', 'total' => 10],
            ['day' => '2026-10-15', 'locale' => 'en_GB', 'version' => 1, 'metric' => 'accept_all', 'total' => 4],
            ['day' => '2026-10-16', 'locale' => 'fr_FR', 'version' => 2, 'metric' => 'reject_all', 'total' => 2],
            ['day' => '2026-10-16', 'locale' => 'fr_FR', 'version' => 2, 'metric' => 'custom', 'total' => 2],
            ['day' => '2026-10-16', 'locale' => 'fr_FR', 'version' => 2, 'metric' => 'changed', 'total' => 1],
            ['day' => '2026-10-16', 'locale' => 'fr_FR', 'version' => 2, 'metric' => 'bogus', 'total' => 99],
        ];
    }

    public function testTotalsChoicesSharesAndResponseRate(): void
    {
        $report = StatsReport::summarise(self::rows());

        $this->assertSame(['shown' => 15, 'accept_all' => 4, 'reject_all' => 2, 'custom' => 2, 'changed' => 1], $report['totals']);
        $this->assertSame(8, $report['choices']);
        $this->assertSame(['accept_all' => 50.0, 'reject_all' => 25.0, 'custom' => 25.0], $report['shares']);
        $this->assertSame(53.3, $report['responseRate']);
    }

    public function testChangedLaterIsNotAFirstChoice(): void
    {
        $report = StatsReport::summarise([
            ['day' => '2026-10-16', 'locale' => 'en_GB', 'version' => 1, 'metric' => 'changed', 'total' => 7],
        ]);

        $this->assertSame(0, $report['choices']);
        $this->assertSame(7, $report['totals']['changed']);
    }

    public function testUnknownMetricsAreIgnoredEverywhere(): void
    {
        $report = StatsReport::summarise(self::rows());

        $this->assertArrayNotHasKey('bogus', $report['totals']);
        $this->assertArrayNotHasKey('bogus', $report['days']['2026-10-16']);
    }

    public function testBreakdownsAreSorted(): void
    {
        $report = StatsReport::summarise(self::rows());

        $this->assertSame(['2026-10-15', '2026-10-16'], array_keys($report['days']));
        $this->assertSame(['en_GB', 'fr_FR'], array_keys($report['locales']));
        $this->assertSame([1, 2], array_keys($report['versions']));
        $this->assertSame(10, $report['days']['2026-10-15']['shown']);
        $this->assertSame(4, $report['locales']['en_GB']['accept_all']);
        $this->assertSame(2, $report['versions'][2]['reject_all']);
    }

    public function testNoRowsGivesZerosAndNoRatesInsteadOfDividingByZero(): void
    {
        $report = StatsReport::summarise([]);

        $this->assertSame(StatsReport::blank(), $report['totals']);
        $this->assertSame(0, $report['choices']);
        $this->assertSame(['accept_all' => null, 'reject_all' => null, 'custom' => null], $report['shares']);
        $this->assertNull($report['responseRate']);
        $this->assertSame([], $report['days']);
    }

    public function testChoicesWithNothingShownHaveNoResponseRate(): void
    {
        $report = StatsReport::summarise([
            ['day' => '2026-10-16', 'locale' => 'en_GB', 'version' => 1, 'metric' => 'accept_all', 'total' => 3],
        ]);

        $this->assertSame(3, $report['choices']);
        $this->assertSame(100.0, $report['shares']['accept_all']);
        $this->assertNull($report['responseRate']);
    }

    public function testNegativeTotalsAreTreatedAsZero(): void
    {
        $report = StatsReport::summarise([
            ['day' => '2026-10-16', 'locale' => 'en_GB', 'version' => 1, 'metric' => 'shown', 'total' => -5],
        ]);

        $this->assertSame(0, $report['totals']['shown']);
    }

    public function testChoicesHelperCountsOnlyFirstChoices(): void
    {
        $this->assertSame(6, StatsReport::choices(['shown' => 100, 'accept_all' => 1, 'reject_all' => 2, 'custom' => 3, 'changed' => 50]));
    }

    public function testPercentRoundsToOneDecimal(): void
    {
        $this->assertSame(33.3, StatsReport::percent(1, 3));
        $this->assertNull(StatsReport::percent(1, 0));
    }
}
