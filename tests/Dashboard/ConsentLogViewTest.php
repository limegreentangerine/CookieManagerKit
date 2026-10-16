<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Dashboard;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Consent\ConsentLogFilter;

require_once __DIR__ . '/translate.php';

final class ConsentLogViewTest extends TestCase
{
    public const HOSTILE = '"><img src=x onerror=alert(1)><script>alert(2)</script>';

    private static function render(array $rows, ConsentLogFilter $filter, int $total, int $page = 1): string
    {
        $view = new class {
            public function action(string $name): string
            {
                return '/dashboard/cookie_manager/consent_log/' . $name;
            }
        };
        $token = new class {
            public function output(string $action): string
            {
                return '<input type="hidden" name="ccm_token" value="t">';
            }
        };
        $form = new class {};
        $perPage = 50;
        $triggers = \CookieManagerKit\Consent\ConsentTrigger::ALL;
        $categories = ['necessary', 'analytics'];
        $capped = false;
        $retentionMonths = 24;

        ob_start();
        include dirname(__DIR__, 2) . '/single_pages/dashboard/cookie_manager/consent_log.php';

        return (string) ob_get_clean();
    }

    private static function row(array $over = []): array
    {
        return $over + [
            'createdAt' => 1760616000,
            'consentId' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
            'version' => 2,
            'categories' => ['necessary', 'analytics'],
            'trigger' => 'reconsent',
            'locale' => 'en_GB',
            'userAgentFamily' => 'Chrome/desktop',
            'reason' => 'Added GA',
        ];
    }

    private static function empty(): ConsentLogFilter
    {
        return ConsentLogFilter::parse(null, null, null, null, null, null, '2026-10-16');
    }

    public function testRowsRenderWithTheReason(): void
    {
        $html = self::render([self::row()], self::empty(), 1);

        $this->assertStringContainsString('3f2504e0-4f89-11d3-9a0c-0305e82c3301', $html);
        $this->assertStringContainsString('Added GA', $html);
        $this->assertStringContainsString('Chrome/desktop', $html);
    }

    public function testHostileRowValuesAreEncoded(): void
    {
        $html = self::render(
            [self::row(['reason' => self::HOSTILE, 'locale' => self::HOSTILE, 'userAgentFamily' => self::HOSTILE, 'trigger' => self::HOSTILE, 'categories' => [self::HOSTILE]])],
            self::empty(),
            1,
        );

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function testASearchThatIsNotAGuidIsShownBackEncodedInTheNoResultsMessage(): void
    {
        $filter = ConsentLogFilter::parse(null, null, null, null, null, self::HOSTILE, '2026-10-16');
        $html = self::render([], $filter, 0);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('is not a valid consent ID', $html);
    }

    public function testTheExportFormCarriesATokenAndTheFilterAsHiddenFields(): void
    {
        $html = self::render([], ConsentLogFilter::parse('2026-10-01', '2026-10-16', 'gpc', '2', 'analytics', null, '2026-10-16'), 0);

        $this->assertStringContainsString('name="ccm_token"', $html);
        $this->assertStringContainsString('/dashboard/cookie_manager/consent_log/export', $html);
        $this->assertStringContainsString('name="trigger" value="gpc"', $html);
    }

    public function testTheRetentionPeriodIsStated(): void
    {
        $this->assertStringContainsString('Banner', self::render([], self::empty(), 0));
    }

    public function testPaginationLinksCarryOnlyVettedFilterValues(): void
    {
        $filter = ConsentLogFilter::parse(null, null, null, null, self::HOSTILE, null, '2026-10-16');
        $html = self::render([self::row()], $filter, 120);

        $this->assertStringContainsString('?page=3', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('onerror', $html);
    }

    public static function setUpBeforeClass(): void
    {
        if (!defined('C5_EXECUTE')) {
            define('C5_EXECUTE', true);
        }
    }
}
