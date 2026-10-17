<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Consent;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Consent\UserAgentFamily;
use PHPUnit\Framework\Attributes\DataProvider;

final class UserAgentFamilyTest extends TestCase
{
    /**
     * @return array<string, array{?string, string}>
     */
    public static function agents(): array
    {
        return [
            'chrome desktop' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36', 'Chrome/desktop'],
            'edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0', 'Edge/desktop'],
            'firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0', 'Firefox/desktop'],
            'safari iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari/mobile'],
            'chrome android phone' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36', 'Chrome/mobile'],
            'ipad' => ['Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'Safari/tablet'],
            'opera' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 OPR/111.0.0.0', 'Opera/desktop'],
            'unknown agent' => ['curl/8.4.0', 'Other/desktop'],
            'null' => [null, 'Other/unknown'],
            'empty' => ['', 'Other/unknown'],
        ];
    }

    #[DataProvider('agents')]
    public function testBuckets(?string $agent, string $expected): void
    {
        $this->assertSame($expected, UserAgentFamily::of($agent));
    }

    public function testNeverEchoesTheRawAgent(): void
    {
        $family = UserAgentFamily::of('"><script>alert(1)</script> Chrome/1 ' . str_repeat('x', 5000));

        $this->assertMatchesRegularExpression('#^[A-Za-z]+/(desktop|mobile|tablet|unknown)\z#', $family);
        $this->assertLessThanOrEqual(32, strlen($family));
    }
}
