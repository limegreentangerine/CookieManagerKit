<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Dashboard;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Entity\Settings;

/**
 * Renders the scanner overview and the scanner settings views outside Concrete with hostile data.
 */
final class ScannerViewTest extends TestCase
{
    public const HOSTILE = '"><img src=x onerror=alert(1)><script>alert(2)</script>';

    private static function view(): object
    {
        return new class {
            public function action(string $name): string
            {
                return '/dashboard/cookie_manager/x/' . $name;
            }
        };
    }

    private static function token(): object
    {
        return new class {
            public function output(string $action): string
            {
                return '<input type="hidden" name="ccm_token" value="t">';
            }
        };
    }

    /**
     * @param list<ScanRun> $runs
     */
    private static function scanner(array $runs, ?ScanRun $lastCrawl, bool $active = false, bool $secretMissing = false, bool $stale = false): string
    {
        $view = self::view();
        $token = self::token();
        $counts = ['unclassified' => 3, 'matched' => 2, 'approved' => 1];
        $lastBeacon = 1700000000;
        $staleDays = 30;
        $scanModeActive = $active;

        ob_start();
        include dirname(__DIR__, 2) . '/single_pages/dashboard/cookie_manager/scanner.php';

        return (string) ob_get_clean();
    }

    public function testAnIncompleteCrawlExplainsWhyNothingIsMarkedGone(): void
    {
        $crawl = (new ScanRun())->start(ScanRun::CRAWL, 1700000000)->setSawEverything(false)->finish(ScanRun::COMPLETE, 1700000100);

        $html = self::scanner([$crawl], $crawl);

        $this->assertStringContainsString('did not see everything', $html);
        $this->assertStringContainsString('nothing is marked as gone', $html);
        $this->assertStringContainsString('members-only', $html);
        $this->assertStringContainsString('exclude list', $html);
    }

    public function testACompleteCrawlSaysItSawEverything(): void
    {
        $crawl = (new ScanRun())->start(ScanRun::CRAWL, 1700000000)->setSawEverything(true)->finish(ScanRun::COMPLETE, 1700000100);

        $this->assertStringContainsString('saw everything', self::scanner([$crawl], $crawl));
    }

    public function testNoCrawlYetShowsNoVerdict(): void
    {
        $html = self::scanner([], null);

        $this->assertStringNotContainsString('saw everything', $html);
        $this->assertStringNotContainsString('did not see everything', $html);
        $this->assertStringContainsString('Nothing has been scanned yet.', $html);
    }

    public function testEveryFormCarriesTheTokenAndTheScanModeButtonsFollowTheState(): void
    {
        $off = self::scanner([], null);
        $on = self::scanner([], null, true);
        $missing = self::scanner([], null, false, true);

        $this->assertStringContainsString('/startScanMode', $off);
        $this->assertStringNotContainsString('/stopScanMode', $off);
        $this->assertStringContainsString('/stopScanMode', $on);
        $this->assertStringNotContainsString('/startScanMode', $missing);
        $this->assertSame(substr_count($off, '<form'), substr_count($off, 'ccm_token'));
        $this->assertStringNotContainsString('cmk_scan_token', $on);
    }

    public function testSettingsAreEncoded(): void
    {
        $settings = new class extends Settings {
            public function getScanExcludesText(): string
            {
                return "/members\n" . ScannerViewTest::HOSTILE;
            }

            public function getScanAlertEmail(): string
            {
                return ScannerViewTest::HOSTILE;
            }
        };
        $view = self::view();
        $token = self::token();

        ob_start();
        include dirname(__DIR__, 2) . '/single_pages/dashboard/cookie_manager/scan_settings.php';
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('allow_private_network', $html);
        $this->assertStringContainsString('site.test', $html);
        $this->assertStringNotContainsString('/refreshNow', $html);
    }

    public static function setUpBeforeClass(): void
    {
        if (!defined('C5_EXECUTE')) {
            define('C5_EXECUTE', true);
        }

        require_once __DIR__ . '/translate.php';
    }
}
