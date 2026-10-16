<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Clean;

final class CleanTest extends TestCase
{
    public function testKeepsMarkupVerbatimBecauseOutputEncodingIsTheDefence(): void
    {
        $this->assertSame('<b>x</b>', Clean::name('<b>x</b>'));
        $this->assertSame('"><img src=x onerror=alert(1)>', Clean::name('"><img src=x onerror=alert(1)>'));
    }

    public function testStripsControlAndFormatCharacters(): void
    {
        $this->assertSame('a[31mb', Clean::name("a\x00\x1b[31m\u{200B}b"));
        $this->assertSame('ab', Clean::name("a\r\nb"));
        $this->assertSame('ab', Clean::name("a\u{2028}b"));
    }

    public function testTrimsAndRejectsEmptyOrNonStrings(): void
    {
        $this->assertSame('_ga', Clean::name("  _ga \n"));
        $this->assertNull(Clean::name('   '));
        $this->assertNull(Clean::name("\x00\x01"));
        $this->assertNull(Clean::name(['_ga']));
        $this->assertNull(Clean::name(null));
    }

    public function testCapsLengthInCharactersNotBytes(): void
    {
        $this->assertSame(128, mb_strlen((string) Clean::name(str_repeat('é', 10000)), 'UTF-8'));
        $this->assertSame('abc', Clean::name('abcdef', 3));
    }

    public function testInvalidUtf8IsScrubbedNotFatal(): void
    {
        $clean = Clean::name("bad\xff\xfename");

        $this->assertIsString($clean);
        $this->assertTrue(mb_check_encoding($clean, 'UTF-8'));
    }
}
