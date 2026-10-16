<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Dashboard;

use PHPUnit\Framework\TestCase;
use ClassKit\Entity\Core\BaseEntity;
use CookieManagerKit\Entity\Service;
use CookieManagerKit\Entity\ScanFinding;

/**
 * Renders the real findings view with hostile data and checks that nothing reaches the page as markup. The static
 * ViewEscapingTest proves every echo uses the encoder; this proves the encoder is applied to the data that matters.
 */
final class FindingsViewTest extends TestCase
{
    private const HOSTILE = '"><img src=x onerror=alert(1)><script>alert(2)</script>';

    private static function finding(int $id, string $kind, string $name, string $host, string $status): ScanFinding
    {
        $finding = (new ScanFinding())->identify($kind, $name, $host)->setStatus($status)->setLastSeen(1700000000)->addSource('crawl');
        (new \ReflectionProperty(BaseEntity::class, 'id'))->setValue($finding, $id);

        return $finding;
    }

    /**
     * @param list<ScanFinding> $findings
     */
    private static function render(array $findings, string $status = '', string $kind = '', string $categoryFilter = '', int $pages = 1): string
    {
        $view = new class {
            public function action(string $name): string
            {
                return '/dashboard/cookie_manager/findings/' . $name;
            }
        };
        $token = new class {
            public function output(string $action): string
            {
                return '<input type="hidden" name="ccm_token" value="t">';
            }
        };
        $categories = ['necessary' => ['id' => 1, 'title' => 'Necessary'], 'analytics' => ['id' => 2, 'title' => self::HOSTILE]];
        $services = [(new Service())->setName(self::HOSTILE)];
        (new \ReflectionProperty(BaseEntity::class, 'id'))->setValue($services[0], 5);
        $total = count($findings);
        $page = 1;

        ob_start();
        include dirname(__DIR__, 2) . '/single_pages/dashboard/cookie_manager/findings.php';

        return (string) ob_get_clean();
    }

    public function testHostileNamesHostsSuggestionsAndTitlesAreEncoded(): void
    {
        $html = self::render([
            self::finding(1, 'cookie', self::HOSTILE, 'example.com', ScanFinding::MATCHED),
            self::finding(2, 'script', 'x', 'cdn.example.net', ScanFinding::UNCLASSIFIED),
        ]);

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function testASuggestionIsShownEncoded(): void
    {
        $finding = self::finding(1, 'cookie', '_ga', 'example.com', ScanFinding::MATCHED)->setSuggestion('analytics', self::HOSTILE);

        $html = self::render([$finding]);

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('analytics &quot;&gt;&lt;img', $html);
    }

    public function testApprovedFindingsOfferNoActionsAndIgnoredOnesOfferReviewAgain(): void
    {
        $html = self::render([
            self::finding(1, 'cookie', 'approved_one', 'example.com', ScanFinding::APPROVED),
            self::finding(2, 'cookie', 'ignored_one', 'example.com', ScanFinding::IGNORED),
        ]);

        $this->assertSame(0, substr_count($html, '/findings/approve'));
        $this->assertSame(1, substr_count($html, '/findings/unignore'));
    }

    public function testAnEmptyListSaysSo(): void
    {
        $this->assertStringContainsString('No findings match', self::render([]));
    }

    public function testAnExamplePageIsPlainEncodedTextNeverALink(): void
    {
        $finding = self::finding(1, 'script', 'x', 'cdn.example.net', ScanFinding::MATCHED)->setExamplePage('/\\evil"><script>alert(3)</script>?a=1&b=2');

        $html = self::render([$finding]);

        $this->assertStringNotContainsString('<script>alert(3)', $html);
        $this->assertStringContainsString('<code>/\\evil&quot;&gt;&lt;script&gt;', $html);
        $this->assertStringNotContainsString('href="/\\evil', $html);
        $this->assertSame(0, substr_count($html, 'href="/'));
        $this->assertSame(0, preg_match('/<a [^>]*href="[^"]*evil/', $html));
    }

    public function testLongNamesAndBidiCharactersAreEncodedAndKeptAsText(): void
    {
        $name = str_repeat('a', 255);
        $bidi = "\u{202E}evil\u{2066}";
        $html = self::render([self::finding(1, 'cookie', $name, 'example.com', ScanFinding::MATCHED)->setSuggestion('analytics', $bidi)]);

        $this->assertStringContainsString($name, $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('evil', $html);
    }

    public function testASuggestionForADeletedCategoryPreselectsARealOne(): void
    {
        $finding = self::finding(1, 'cookie', '_ga', 'example.com', ScanFinding::MATCHED)->setSuggestion('deleted_category', 'Google');

        $html = self::render([$finding]);

        $this->assertStringContainsString('<option value="1" selected>', $html);
        $this->assertStringNotContainsString('deleted_category', substr($html, (int) strpos($html, '<select class="form-select mb-2" name="categoryID">')));
    }

    public function testAnApprovedFindingWhoseServiceWasDeletedStillRenders(): void
    {
        $finding = self::finding(1, 'cookie', '_ga', 'example.com', ScanFinding::APPROVED);

        $this->assertNull($finding->getService());
        $this->assertStringContainsString('<code>_ga</code>', self::render([$finding]));
    }

    public function testTheCategoryFilterOffersNoneAndEveryCategoryAndKeepsTheChoice(): void
    {
        $html = self::render([], '', '', 'analytics');

        $this->assertStringContainsString('name="category"', $html);
        $this->assertStringContainsString('<option value="none" >', $html);
        $this->assertStringContainsString('<option value="necessary" >', $html);
        $this->assertStringContainsString('<option value="analytics" selected>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
    }

    public function testPaginationLinksKeepTheFiltersEncoded(): void
    {
        $finding = self::finding(1, 'cookie', '_ga', 'example.com', ScanFinding::MATCHED);

        $html = self::render([$finding], 'matched', 'cookie', 'none', 2);

        $this->assertStringContainsString('href="?page=2&amp;status=matched&amp;kind=cookie&amp;category=none"', $html);
    }

    public static function setUpBeforeClass(): void
    {
        if (!defined('C5_EXECUTE')) {
            define('C5_EXECUTE', true);
        }

        require_once __DIR__ . '/translate.php';
    }
}
