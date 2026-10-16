<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\BotFilter;
use PHPUnit\Framework\Attributes\DataProvider;

final class BotFilterTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string}>
     */
    public static function bots(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'blank' => ['   '],
            'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
            'headless chrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 HeadlessChrome/120.0.0.0 Safari/537.36'],
            'lighthouse' => ['Mozilla/5.0 Chrome-Lighthouse'],
            'curl' => ['curl/8.4.0'],
            'wget' => ['Wget/1.21'],
            'python' => ['python-requests/2.31'],
            'upper case marker' => ['SOMECRAWLER/1.0'],
        ];
    }

    #[DataProvider('bots')]
    public function testRecognisesBots(?string $userAgent): void
    {
        $this->assertTrue(BotFilter::isBot($userAgent));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function browsers(): array
    {
        return [
            'chrome' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'],
            'safari ios' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'],
            'firefox' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0'],
            'edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0'],
        ];
    }

    #[DataProvider('browsers')]
    public function testLetsRealBrowsersThrough(string $userAgent): void
    {
        $this->assertFalse(BotFilter::isBot($userAgent));
    }
}
