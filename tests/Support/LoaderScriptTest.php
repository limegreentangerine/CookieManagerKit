<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\Output;
use CookieManagerKit\Support\LoaderScript;

final class LoaderScriptTest extends TestCase
{
    public function testSourceIsTheLoaderAndCannotCloseAScriptElement(): void
    {
        $source = LoaderScript::source();

        $this->assertStringContainsString('cmk_consent', $source);
        $this->assertStringNotContainsString('</script', strtolower($source));
    }

    public function testInlineCarriesTheBootDataAndTheLoader(): void
    {
        $html = LoaderScript::inline(['v' => 3, 'cats' => ['necessary', 'analytics'], 'days' => 180, 'cfg' => '/cookie-manager/config/en_GB.json?v=abc', 'post' => '/cookie-manager/consent']);

        $this->assertStringStartsWith('<script>window.cmkBoot=', $html);
        $this->assertStringEndsWith('</script>', $html);
        $this->assertStringContainsString('"v":3', $html);
        $this->assertStringContainsString('cmk_consent', $html);
        $this->assertSame(1, substr_count(strtolower($html), '</script'));
    }

    public function testHostileBootValuesCannotBreakOutOfTheScript(): void
    {
        $html = LoaderScript::inline(['cats' => ['</script><script>alert(1)</script>', '"><img src=x onerror=alert(1)>'], 'cfg' => "';alert(1);//"]);

        $this->assertSame(1, substr_count(strtolower($html), '</script'));
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
    }

    public function testNonceIsEmittedOnlyWhenItLooksLikeOne(): void
    {
        $this->assertStringStartsWith('<script nonce="abcDEF123456==">', LoaderScript::inline([], 'abcDEF123456=='));

        foreach (['', 'short', 'bad"nonce onload="alert(1)"', "line\nbreak12345", '<x>12345678'] as $bad) {
            $this->assertStringStartsWith('<script>', LoaderScript::inline([], $bad), $bad);
        }

        $this->assertStringStartsWith('<script>', LoaderScript::inline([], null));
    }

    public function testBootBuildsExactlyTheKeysTheLoaderReads(): void
    {
        $boot = LoaderScript::boot(3, ['necessary', 'analytics'], 180, '/cfg.json?v=a', '/post', 'abcDEF123456==', '/event');

        $this->assertSame(['v', 'cats', 'days', 'cfg', 'post', 'event', 'scan', 'nonce'], array_keys($boot));
        $this->assertSame(3, $boot['v']);
        $this->assertSame(['necessary', 'analytics'], $boot['cats']);
        $this->assertSame(180, $boot['days']);
        $this->assertSame('/cfg.json?v=a', $boot['cfg']);
        $this->assertSame('/post', $boot['post']);
        $this->assertSame('/event', $boot['event']);
        $this->assertTrue($boot['nonce']);
    }

    public function testBootEventUrlDefaultsToEmptyAndIsEncoded(): void
    {
        $this->assertSame('', LoaderScript::boot(1, [], 1, '', '', null)['event']);

        $json = Output::json(LoaderScript::boot(1, [], 1, '', '', null, '"><script>x</script>'));

        foreach (['<', '>', '&', "'"] as $char) {
            $this->assertStringNotContainsString($char, $json);
        }
    }

    public function testBootNonceFlagIsTrueOnlyForAValidNonce(): void
    {
        $this->assertTrue(LoaderScript::boot(1, [], 1, '', '', 'abcDEF123456==')['nonce']);

        foreach ([null, '', 'short', 'bad"nonce1234', 'bad nonce1234', "line\nbreak12345", "trail12345678\n", "it's12345678"] as $bad) {
            $this->assertFalse(LoaderScript::boot(1, [], 1, '', '', $bad)['nonce'], (string) $bad);
        }
    }

    public function testBootEncodesHostileStringsWithoutMarkupCharacters(): void
    {
        $boot = LoaderScript::boot(1, ["<a>&'x\"", '</script>'], 1, "';alert(1);//<>&", '"><img>', null);
        $json = Output::json($boot);

        foreach (['<', '>', '&', "'"] as $char) {
            $this->assertStringNotContainsString($char, $json);
        }
    }

    public function testBootScanUrlDefaultsToEmptyAndIsEncoded(): void
    {
        $this->assertSame('', LoaderScript::boot(1, [], 1, '', '', null)['scan']);

        $json = Output::json(LoaderScript::boot(1, [], 1, '', '', null, '', '"><script>x</script>'));

        foreach (['<', '>', '&', "'"] as $char) {
            $this->assertStringNotContainsString($char, $json);
        }
    }
}
