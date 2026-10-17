<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Dashboard/translate.php';

final class BlockViewTest extends TestCase
{
    private function render(array $sections, bool $editMode = false): string
    {
        if (!defined('C5_EXECUTE')) {
            define('C5_EXECUTE', true);
        }

        $c = new class($editMode) {
            public function __construct(private bool $edit) {}
            public function isEditMode(): bool
            {
                return $this->edit;
            }
        };

        ob_start();
        (static function () use ($sections, $c): void {
            require dirname(__DIR__) . '/blocks/cookie_declaration/view.php';
        })();

        return (string) ob_get_clean();
    }

    public function testHostileAdminTextIsEscaped(): void
    {
        $html = $this->render([[
            'handle' => 'analytics',
            'title' => '<script>alert(1)</script>',
            'description' => '"><img src=x onerror=1>',
            'cookies' => [['name' => '<b>_ga</b>', 'provider' => '"x"', 'purpose' => '<script>bad()</script>', 'duration' => '1 <i>day</i>']],
        ]]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;script&gt;bad()&lt;/script&gt;', $html);
    }

    public function testTheChangeConsentButtonOpensTheBanner(): void
    {
        $this->assertStringContainsString('data-cmk-open', $this->render([['handle' => 'a', 'title' => 'A', 'description' => '', 'cookies' => [['name' => 'x', 'provider' => '', 'purpose' => '', 'duration' => '']]]]));
    }

    public function testWithNothingToListThereIsStillTheButtonAndAnEmptyNote(): void
    {
        $html = $this->render([]);

        $this->assertStringContainsString('data-cmk-open', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    public function testEditModeShowsAPlaceholderInsteadOfTheTables(): void
    {
        $html = $this->render([['handle' => 'a', 'title' => 'A', 'description' => '', 'cookies' => [['name' => 'x', 'provider' => '', 'purpose' => '', 'duration' => '']]]], true);

        $this->assertStringContainsString('ccm-edit-mode-disabled-item', $html);
        $this->assertStringNotContainsString('<table', $html);
    }
}
