<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\LocaleResolver;

final class LocaleResolverTest extends TestCase
{
    private const SITE = ['en_GB', 'cy_GB'];

    public function testMatchesASiteLocaleCaseInsensitivelyAndReturnsTheSiteSpelling(): void
    {
        $this->assertSame('cy_GB', LocaleResolver::resolve('cy_GB', self::SITE, 'en_GB'));
        $this->assertSame('cy_GB', LocaleResolver::resolve('CY_gb', self::SITE, 'en_GB'));
    }

    public function testUnknownLocalesResolveToTheDefaultAndAreNeverEchoed(): void
    {
        foreach (['fr_FR', '', '<script>alert(1)</script>', '../../etc/passwd', "en_GB\n", str_repeat('a', 5000), 'en-GB'] as $hostile) {
            // None of these is a site locale, so the default comes back and the hostile value is not echoed.
            $this->assertSame('en_GB', LocaleResolver::resolve($hostile, self::SITE, 'en_GB'));
        }
    }
}
