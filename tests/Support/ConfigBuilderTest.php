<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\ConfigBuilder;

final class ConfigBuilderTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function data(): array
    {
        return [
            'settings' => [
                'policyVersion' => 3,
                'lifetimeDays' => 180,
                'widget' => true,
                'position' => 'bottom-left',
                'layout' => 'inline',
                'font' => 'system',
                'radius' => 12,
                'shadow' => false,
                'tokens' => ['bg' => '#101010', 'fg' => '#f0f0f0', 'accent' => '#ff0000', 'buttonBg' => '#00ff00', 'buttonFg' => '#000000'],
            ],
            'banner' => [
                'en_GB' => [
                    'title' => 'Your privacy', 'body' => "We use cookies.\nChoose below.", 'acceptAll' => 'Accept all', 'rejectAll' => 'Reject all',
                    'customise' => 'Customise', 'save' => 'Save', 'widget' => 'Cookie settings', 'policyLinkText' => 'Cookie policy',
                    'policyUrl' => 'https://example.com/cookies',
                ],
                'cy_GB' => [
                    'title' => 'Eich preifatrwydd', 'body' => '', 'acceptAll' => 'Derbyn pob un', 'rejectAll' => '',
                    'customise' => '', 'save' => '', 'widget' => '', 'policyLinkText' => '', 'policyUrl' => '',
                ],
            ],
            'categories' => [
                [
                    'handle' => 'marketing', 'necessary' => false, 'sort' => 30,
                    'content' => ['en_GB' => ['title' => 'Marketing', 'description' => 'Ads']],
                    'services' => [['name' => 'Meta Pixel', 'vendor' => 'Meta', 'content' => ['en_GB' => 'Tracks conversions']]],
                ],
                [
                    'handle' => 'necessary', 'necessary' => true, 'sort' => 0,
                    'content' => ['en_GB' => ['title' => 'Necessary', 'description' => 'Required'], 'cy_GB' => ['title' => 'Angenrheidiol', 'description' => '']],
                    'services' => [],
                ],
            ],
            'defaults' => [
                'title' => 'We value your privacy', 'body' => 'We use cookies.', 'acceptAll' => 'Accept all', 'rejectAll' => 'Reject all',
                'customise' => 'Customise', 'save' => 'Save my choices', 'widget' => 'Cookie settings', 'policyLinkText' => 'Cookie policy',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function build(array $data, string $locale = 'en_GB'): array
    {
        return ConfigBuilder::build($data, $locale, 'en_GB', ['CONCRETE', 'cmk_consent']);
    }

    public function testBuildsTheDocumentedShape(): void
    {
        $config = self::build(self::data());

        $this->assertSame(['version', 'policyVersion', 'locale', 'banner', 'categories', 'style', 'lifetimeDays', 'consentMode', 'defaultModel', 'widget'], array_keys($config));
        $this->assertSame(3, $config['policyVersion']);
        $this->assertSame('en_GB', $config['locale']);
        $this->assertSame('Your privacy', $config['banner']['title']);
        $this->assertSame('https://example.com/cookies', $config['banner']['policyUrl']);
        $this->assertSame('Accept all', $config['banner']['labels']['acceptAll']);
        $this->assertSame('bottom-left', $config['style']['position']);
        $this->assertSame(12, $config['style']['radius']);
        $this->assertFalse($config['style']['shadow']);
        $this->assertSame(180, $config['lifetimeDays']);
        $this->assertSame(12, strlen($config['version']));
    }

    public function testIconUrlIsPassedThroughOnlyWhenSafe(): void
    {
        $data = self::data();
        $this->assertSame('', self::build($data)['style']['iconUrl']);

        $data['settings']['iconUrl'] = '/application/files/1/icon.png';
        $this->assertSame('/application/files/1/icon.png', self::build($data)['style']['iconUrl']);

        $data['settings']['iconUrl'] = 'javascript:alert(1)';
        $this->assertSame('', self::build($data)['style']['iconUrl']);
    }

    public function testCategoriesAreSortedAndNecessaryListsTheEssentialCookies(): void
    {
        $config = self::build(self::data());

        $this->assertSame(['necessary', 'marketing'], array_column($config['categories'], 'handle'));
        $this->assertSame(
            [
                ['name' => 'CONCRETE', 'purpose' => '', 'duration' => ''],
                ['name' => 'cmk_consent', 'purpose' => '', 'duration' => ''],
            ],
            $config['categories'][0]['cookies'],
        );
        $this->assertSame([], $config['categories'][1]['cookies']);
        $this->assertSame('Tracks conversions', $config['categories'][1]['services'][0]['description']);
    }

    public function testEmptyFieldsFallBackPerFieldToTheDefaultLocaleThenToBuiltInText(): void
    {
        $config = self::build(self::data(), 'cy_GB');

        $this->assertSame('cy_GB', $config['locale']);
        $this->assertSame('Eich preifatrwydd', $config['banner']['title']);            // own value
        $this->assertSame("We use cookies.\nChoose below.", $config['banner']['body']); // default locale's value
        $this->assertSame('Derbyn pob un', $config['banner']['labels']['acceptAll']);   // own value
        $this->assertSame('Reject all', $config['banner']['labels']['rejectAll']);      // default locale's value
        $this->assertSame('https://example.com/cookies', $config['banner']['policyUrl']);
        $this->assertSame('Angenrheidiol', $config['categories'][0]['title']);
        $this->assertSame('Required', $config['categories'][0]['description']);        // empty Welsh description -> English
    }

    public function testNothingConfiguredUsesTheBuiltInEnglishText(): void
    {
        $data = self::data();
        $data['banner'] = [];
        $data['categories'][1]['content'] = [];

        $config = self::build($data);

        $this->assertSame('We value your privacy', $config['banner']['title']);
        $this->assertSame('Save my choices', $config['banner']['labels']['save']);
        $this->assertSame('necessary', $config['categories'][0]['title']);              // handle as the last resort
    }

    public function testAnUnknownLocaleRendersTheDefaultLocalesContent(): void
    {
        $config = self::build(self::data(), 'fr_FR');

        $this->assertSame('Your privacy', $config['banner']['title']);
    }

    public function testStyleValuesAreRevalidatedWhenBuilt(): void
    {
        $data = self::data();
        $data['settings']['position'] = '"><script>';
        $data['settings']['layout'] = 'x';
        $data['settings']['font'] = 'url(x)';
        $data['settings']['radius'] = 99999;
        $data['settings']['tokens']['bg'] = 'red;}</style><script>alert(1)</script>';
        $data['settings']['tokens']['fg'] = ['#ffffff'];

        $style = self::build($data)['style'];

        $this->assertSame('bottom-right', $style['position']);
        $this->assertSame('stacked', $style['layout']);
        $this->assertSame('inherit', $style['font']);
        $this->assertSame(32, $style['radius']);
        $this->assertSame('#ffffff', $style['tokens']['bg']);
        $this->assertSame('#111111', $style['tokens']['fg']);
    }

    public function testUnsafePolicyUrlsAreRemoved(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.example', "java\tscript:x"] as $url) {
            $data = self::data();
            $data['banner']['en_GB']['policyUrl'] = $url;
            $data['banner']['cy_GB']['policyUrl'] = '';

            $this->assertSame('', self::build($data)['banner']['policyUrl'], $url);
        }
    }

    public function testInvalidCategoryHandlesAreSkipped(): void
    {
        $data = self::data();
        $data['categories'][] = ['handle' => '<script>', 'necessary' => false, 'sort' => 1, 'content' => [], 'services' => []];
        $data['categories'][] = ['handle' => "analytics\n", 'necessary' => false, 'sort' => 2, 'content' => [], 'services' => []];

        $this->assertSame(['necessary', 'marketing'], array_column(self::build($data)['categories'], 'handle'));
    }

    public function testHostileContentCannotBreakOutOfTheSerialisedJson(): void
    {
        $data = self::data();
        $data['banner']['en_GB']['title'] = '</script><script>alert(1)</script>';
        $data['banner']['en_GB']['body'] = '"><img src=x onerror=alert(1)>';
        $data['categories'][1]['services'][0]['name'] = "'; alert(1); //";

        $json = \CookieManagerKit\Support\Output::json(self::build($data));

        foreach (['<', '>', '&', "'"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
        $this->assertSame('</script><script>alert(1)</script>', json_decode($json, true)['banner']['title']);
    }

    public function testVersionIsStableAndChangesWithContentAndWithTheEssentialCookieNames(): void
    {
        $a = self::build(self::data())['version'];
        $b = self::build(self::data())['version'];
        $this->assertSame($a, $b);

        $changed = self::data();
        $changed['banner']['en_GB']['title'] = 'Different';
        $this->assertNotSame($a, self::build($changed)['version']);

        $renamed = ConfigBuilder::build(self::data(), 'en_GB', 'en_GB', ['MYSITE', 'cmk_consent']);
        $this->assertNotSame($a, $renamed['version']);
    }

    public function testCookieRowsAreNormalised(): void
    {
        $config = ConfigBuilder::build(self::data(), 'en_GB', 'en_GB', [
            'plain',
            ['name' => 'rich', 'purpose' => 'Does a thing.', 'duration' => '2 weeks'],
            ['name' => 'partial', 'purpose' => 5],
            ['purpose' => 'no name'],
            '',
            42,
        ]);

        $this->assertSame([
            ['name' => 'plain', 'purpose' => '', 'duration' => ''],
            ['name' => 'rich', 'purpose' => 'Does a thing.', 'duration' => '2 weeks'],
            ['name' => 'partial', 'purpose' => '', 'duration' => ''],
        ], $config['categories'][0]['cookies']);
    }

    public function testVersionChangesWhenTheCookieDetailsChange(): void
    {
        $version = static fn(array $cookies): string => ConfigBuilder::build(self::data(), 'en_GB', 'en_GB', $cookies)['version'];

        $this->assertSame($version(['a']), $version(['a']));
        $this->assertNotSame($version(['a']), $version(['b']));
        $this->assertNotSame($version([['name' => 'a', 'purpose' => 'x']]), $version([['name' => 'a', 'purpose' => 'y']]));
    }

    public function testConsentModeAndDefaultModelReachTheConfig(): void
    {
        $data = ['settings' => ['consentMode' => true, 'defaultModel' => 'opt_out'], 'categories' => [], 'banner' => [], 'defaults' => []];
        $config = ConfigBuilder::build($data, 'en_GB', 'en_GB', []);

        $this->assertTrue($config['consentMode']);
        $this->assertSame('opt_out', $config['defaultModel']);
    }

    public function testTheirDefaultsAreOffAndOptIn(): void
    {
        $config = ConfigBuilder::build(['settings' => [], 'categories' => [], 'banner' => [], 'defaults' => []], 'en_GB', 'en_GB', []);

        $this->assertFalse($config['consentMode']);
        $this->assertSame('opt_in', $config['defaultModel']);
    }

    public function testAHostileModelFallsBackToOptIn(): void
    {
        $config = ConfigBuilder::build(['settings' => ['defaultModel' => '"><script>'], 'categories' => [], 'banner' => [], 'defaults' => []], 'en_GB', 'en_GB', []);

        $this->assertSame('opt_in', $config['defaultModel']);
    }

    public function testChangingTheModelChangesTheConfigVersion(): void
    {
        $a = ConfigBuilder::build(['settings' => ['defaultModel' => 'opt_in']], 'en_GB', 'en_GB', []);
        $b = ConfigBuilder::build(['settings' => ['defaultModel' => 'opt_out']], 'en_GB', 'en_GB', []);

        $this->assertNotSame($a['version'], $b['version']);
    }
}
