<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\StatsRange;
use PHPUnit\Framework\Attributes\DataProvider;

final class StatsRangeTest extends TestCase
{
    private const TODAY = '2026-10-16';

    public function testDefaultsToTheLastThirtyDaysEndingToday(): void
    {
        $this->assertSame(['from' => '2026-09-17', 'to' => '2026-10-16'], StatsRange::parse(null, null, self::TODAY));
    }

    public function testKeepsAValidRange(): void
    {
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-10'], StatsRange::parse('2026-10-01', '2026-10-10', self::TODAY));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalid(): array
    {
        return [
            'html' => ['<script>alert(1)</script>'],
            'impossible date' => ['2026-02-30'],
            'no zero padding' => ['2026-1-5'],
            'empty' => [''],
            'integer' => [5],
            'array' => [['2026-10-01']],
            'trailing newline' => ["2026-10-01\n"],
            'long text' => [str_repeat('9', 5000)],
        ];
    }

    #[DataProvider('invalid')]
    public function testFallsBackToTheDefaultForAnInvalidFrom(mixed $value): void
    {
        $this->assertSame(['from' => '2026-09-17', 'to' => '2026-10-16'], StatsRange::parse($value, null, self::TODAY));
    }

    #[DataProvider('invalid')]
    public function testFallsBackToTodayForAnInvalidTo(mixed $value): void
    {
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-16'], StatsRange::parse('2026-10-01', $value, self::TODAY));
    }

    public function testAnEndInTheFutureIsClampedToToday(): void
    {
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-16'], StatsRange::parse('2026-10-01', '2027-01-01', self::TODAY));
    }

    public function testAReversedRangeFallsBackToThirtyDaysBeforeTheEnd(): void
    {
        $this->assertSame(['from' => '2026-09-02', 'to' => '2026-10-01'], StatsRange::parse('2026-10-10', '2026-10-01', self::TODAY));
    }

    public function testAStartInTheFutureFallsBackToThirtyDaysBeforeTheEnd(): void
    {
        $this->assertSame(['from' => '2026-09-17', 'to' => '2026-10-16'], StatsRange::parse('2027-01-01', null, self::TODAY));
    }

    public function testARangeLongerThanAYearIsCappedAt366Days(): void
    {
        $this->assertSame(['from' => '2025-10-16', 'to' => '2026-10-16'], StatsRange::parse('2016-01-01', '2026-10-16', self::TODAY));
    }
}
