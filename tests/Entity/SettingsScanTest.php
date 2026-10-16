<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\Settings;
use PHPUnit\Framework\Attributes\DataProvider;

final class SettingsScanTest extends TestCase
{
    public function testDefaults(): void
    {
        $settings = new Settings();

        $this->assertSame(50, $settings->getScanMaxPages());
        $this->assertSame([], $settings->getScanExcludes());
        $this->assertFalse($settings->isScanScheduleEnabled());
        $this->assertSame('', $settings->getScanAlertEmail());
        $this->assertSame(60, $settings->getScanStaleDays());
        $this->assertFalse($settings->isClassificationRefreshEnabled());
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function pageCaps(): array
    {
        return [
            'zero becomes one' => [0, 1],
            'negative becomes one' => [-4, 1],
            'too large is capped' => [99999, 500],
            'text falls back to the default' => ['abc', 50],
            'numeric text is used' => ['120', 120],
            'null falls back' => [null, 50],
        ];
    }

    #[DataProvider('pageCaps')]
    public function testMaxPagesIsClamped(mixed $given, int $expected): void
    {
        $this->assertSame($expected, (new Settings())->setScanMaxPages($given)->getScanMaxPages());
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function staleDays(): array
    {
        return [
            'too small' => [1, 7],
            'too large' => [9999, 365],
            'text' => ['x', 60],
            'valid' => [30, 30],
        ];
    }

    #[DataProvider('staleDays')]
    public function testStaleDaysIsClamped(mixed $given, int $expected): void
    {
        $this->assertSame($expected, (new Settings())->setScanStaleDays($given)->getScanStaleDays());
    }

    public function testExcludesKeepOnlyPlainPathPatterns(): void
    {
        $settings = (new Settings())->setScanExcludesText("/members\n  /shop/*  \nno-slash\n/bad path\n<script>\n/members\nhttps://evil.example/x\n/ok-path_1.2~x%20y");

        $this->assertSame(['/members', '/shop/*', '/ok-path_1.2~x%20y'], $settings->getScanExcludes());
        $this->assertSame("/members\n/shop/*\n/ok-path_1.2~x%20y", $settings->getScanExcludesText());
    }

    public function testExcludesAreCapped(): void
    {
        $lines = implode("\n", array_map(static fn(int $i): string => '/p' . $i, range(1, 80)));

        $this->assertCount(50, (new Settings())->setScanExcludesText($lines)->getScanExcludes());
    }

    public function testNonStringExcludesAreIgnored(): void
    {
        $this->assertSame([], (new Settings())->setScanExcludesText(['/a'])->getScanExcludes());
    }

    public function testAlertEmailMustBeAValidAddress(): void
    {
        $settings = new Settings();

        $this->assertSame('ops@example.com', $settings->setScanAlertEmail(' ops@example.com ')->getScanAlertEmail());
        $this->assertSame('', $settings->setScanAlertEmail("a@example.com\nBcc: b@example.com")->getScanAlertEmail());
        $this->assertSame('', $settings->setScanAlertEmail('not an email')->getScanAlertEmail());
        $this->assertSame('', $settings->setScanAlertEmail(null)->getScanAlertEmail());
    }

    public function testSwitchesCoerceToBool(): void
    {
        $settings = (new Settings())->setScanScheduleEnabled('1')->setClassificationRefreshEnabled(1);

        $this->assertTrue($settings->isScanScheduleEnabled());
        $this->assertTrue($settings->isClassificationRefreshEnabled());
    }
}
