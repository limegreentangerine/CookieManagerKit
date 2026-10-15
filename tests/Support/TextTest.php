<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use CookieManagerKit\Support\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testPlainStripsMarkupControlCharactersAndTruncates(): void
    {
        $this->assertSame('alert(1)Hi', Text::plain('<script>alert(1)</script>Hi'));
        $this->assertSame('a b', Text::plain("a\n\tb"));
        $this->assertSame(5, mb_strlen(Text::plain('abcdefghij', 5)));
        $this->assertSame('', Text::plain(null));
        $this->assertSame('', Text::plain("\xB1\xB2"));
    }

    public function testMultilineKeepsLineBreaksButNotMarkup(): void
    {
        $this->assertSame("one\ntwo", Text::multiline("one\r\ntwo"));
        $this->assertSame("one\n\ntwo", Text::multiline("one\n\n\n\n\ntwo"));
        $this->assertSame('xHi', Text::multiline('<img src=x onerror=alert(1)>xHi'));
        $this->assertSame(3, mb_strlen(Text::multiline('abcdef', 3)));
    }

    public function testNonStringInputIsTreatedAsEmptyNotAsAnError(): void
    {
        foreach ([['x'], ['a' => ['b']], 5, 1.5, true, new \stdClass()] as $value) {
            $this->assertSame('', Text::plain($value));
            $this->assertSame('', Text::multiline($value));
        }
    }

    public function testIsLocale(): void
    {
        foreach (['en', 'en_GB', 'cy_GB', 'sr_Latn_RS', 'zh_CN'] as $ok) {
            $this->assertTrue(Text::isLocale($ok), $ok);
        }
        foreach (['', 'e', 'en-GB', "en_GB\n", '<script>', '../x', str_repeat('a', 40), 5, null] as $bad) {
            $this->assertFalse(Text::isLocale($bad), var_export($bad, true));
        }
    }

    public function testIsHandle(): void
    {
        foreach (['necessary', 'analytics', 'my-cat_2'] as $ok) {
            $this->assertTrue(Text::isHandle($ok), $ok);
        }
        foreach (['', 'Upper', "analytics\n", '<script>', 'a b', str_repeat('a', 33), 5, null] as $bad) {
            $this->assertFalse(Text::isHandle($bad), var_export($bad, true));
        }
    }
}
