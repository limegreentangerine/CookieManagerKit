<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\StyleRules;
use PHPUnit\Framework\Attributes\DataProvider;

final class StyleRulesTest extends TestCase
{
    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function hexProvider(): array
    {
        return [
            'valid lower' => ['#0b5fff', true],
            'valid upper' => ['#0B5FFF', true],
            'short form' => ['#fff', false],
            'no hash' => ['0b5fff', false],
            'css injection' => ['red;}</style><script>alert(1)</script>', false],
            'expression' => ['#fff; background:url(javascript:alert(1))', false],
            'trailing newline' => ["#0b5fff\n", false],
            'array' => [['#0b5fff'], false],
            'null' => [null, false],
        ];
    }

    #[DataProvider('hexProvider')]
    public function testIsHex(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, StyleRules::isHex($value));
    }

    public function testHexFallsBackToDefaultAndLowerCases(): void
    {
        $this->assertSame('#0b5fff', StyleRules::hex('#0B5FFF', '#000000'));
        $this->assertSame('#000000', StyleRules::hex('url(x)', '#000000'));
    }

    public function testChoiceOnlyReturnsAllowedValues(): void
    {
        $this->assertSame('inline', StyleRules::choice('inline', StyleRules::LAYOUTS, 'stacked'));
        $this->assertSame('stacked', StyleRules::choice('"><script>', StyleRules::LAYOUTS, 'stacked'));
        $this->assertSame('stacked', StyleRules::choice(['inline'], StyleRules::LAYOUTS, 'stacked'));
    }

    public function testRadiusIsClamped(): void
    {
        $this->assertSame(0, StyleRules::radius(-5));
        $this->assertSame(32, StyleRules::radius(999));
        $this->assertSame(8, StyleRules::radius('8'));
        $this->assertSame(8, StyleRules::radius('nonsense'));
    }

    public function testLifetime(): void
    {
        $this->assertSame(180, StyleRules::lifetime(null));
        $this->assertSame(30, StyleRules::lifetime(1));
        $this->assertSame(395, StyleRules::lifetime(9999));
        $this->assertSame(90, StyleRules::lifetime('90'));
        $this->assertTrue(StyleRules::isValidLifetime('90'));
        $this->assertFalse(StyleRules::isValidLifetime('9'));
        $this->assertFalse(StyleRules::isValidLifetime('abc'));
        $this->assertFalse(StyleRules::isValidLifetime('90.5'));
    }
}
