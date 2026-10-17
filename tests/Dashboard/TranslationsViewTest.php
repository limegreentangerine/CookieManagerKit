<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Dashboard;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/translate.php';

final class TranslationsViewTest extends TestCase
{
    private const HOSTILE = '<b>Fran&ccedil;ais</b>';

    /**
     * @param array<string, string> $locales
     */
    private static function render(array $locales, string $defaultLocale = 'en_GB', ?array $matrix = null): string
    {
        $view = new class {
            public function action(string $name): string
            {
                return '/dashboard/cookie_manager/translations/' . $name;
            }

            public function url(string $path): string
            {
                return $path;
            }
        };
        $token = new class {
            public function output(string $action): string
            {
                return '<input type="hidden" name="ccm_token" value="t">';
            }
        };
        $areas = [
            'banner' => ['Banner', '/dashboard/cookie_manager/banner'],
            'category' => ['Categories', '/dashboard/cookie_manager/categories'],
            'service' => ['Services', '/dashboard/cookie_manager/services'],
            'cookie' => ['Cookies', '/dashboard/cookie_manager/services'],
        ];
        $matrix ??= self::matrix(array_keys($locales));

        ob_start();
        include dirname(__DIR__, 2) . '/single_pages/dashboard/cookie_manager/translations.php';

        return (string) ob_get_clean();
    }

    /**
     * @param list<string> $locales
     */
    private static function matrix(array $locales): array
    {
        $matrix = [];

        foreach (['banner', 'category', 'service', 'cookie'] as $area) {
            foreach ($locales as $locale) {
                $matrix[$area][$locale] = $locale === 'fr_FR' ? ['filled' => 3, 'total' => 7] : ['filled' => 7, 'total' => 7];
            }
        }

        return $matrix;
    }

    public function testEveryNonDefaultLocaleGetsCopyExportAndImportFormsAndTheDefaultGetsNone(): void
    {
        $html = self::render(['en_GB' => 'English (en_GB)', 'fr_FR' => self::HOSTILE]);

        $this->assertSame(3, substr_count($html, 'name="locale" value="fr_FR"'));
        $this->assertStringNotContainsString('name="locale" value="en_GB"', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertSame(3, substr_count($html, 'name="ccm_token"'));
        foreach (['copy', 'export', 'import'] as $action) {
            $this->assertStringContainsString('action="/dashboard/cookie_manager/translations/' . $action . '"', $html);
        }
    }

    public function testLocaleLabelsAreEscaped(): void
    {
        $html = self::render(['en_GB' => 'English (en_GB)', 'fr_FR' => '<b>Français</b>']);

        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
    }

    public function testCountsAreShownAndNoFormUsesGet(): void
    {
        $html = self::render(['en_GB' => 'English (en_GB)', 'fr_FR' => 'Français']);

        $this->assertStringContainsString('3 / 7', $html);
        $this->assertStringContainsString('7 / 7', $html);
        $this->assertStringNotContainsStringIgnoringCase('method="get"', $html);
        $this->assertSame(substr_count($html, '<form'), substr_count($html, 'method="post"'));
    }

    public function testInstructionsAndTheReplaceWarningAreShown(): void
    {
        $html = self::render(['en_GB' => 'English (en_GB)', 'fr_FR' => 'Français']);

        $this->assertStringContainsString('CSV UTF-8 (comma delimited)', $html);
        $this->assertStringContainsString('translation', $html);
        $this->assertStringContainsString('replaces', $html);
    }

    public function testASingleLocaleSiteRendersWithoutForms(): void
    {
        $html = self::render(['en_GB' => 'English (en_GB)']);

        $this->assertStringContainsString('single language', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    public function testAnEmptyMatrixOfZerosRenders(): void
    {
        $matrix = [];

        foreach (['banner', 'category', 'service', 'cookie'] as $area) {
            foreach (['en_GB', 'fr_FR'] as $locale) {
                $matrix[$area][$locale] = ['filled' => 0, 'total' => 0];
            }
        }

        $html = self::render(['en_GB' => 'English', 'fr_FR' => 'Français'], 'en_GB', $matrix);

        $this->assertStringContainsString('0 / 0', $html);
        $this->assertStringNotContainsString('text-success', $html);
    }

    public static function setUpBeforeClass(): void
    {
        if (!defined('C5_EXECUTE')) {
            define('C5_EXECUTE', true);
        }
    }
}
