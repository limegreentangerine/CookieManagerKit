<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\TrackingCode;

final class TrackingCodeTest extends TestCase
{
    public function testExternalAndInlineScriptsBecomeInert(): void
    {
        $code = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>'
            . '<script>window.dataLayer=window.dataLayer||[];</script>';

        $inert = TrackingCode::makeInert($code, 'analytics');

        $this->assertSame(2, substr_count($inert, 'type="text/plain" data-cmk-category="analytics"'));
        $this->assertStringContainsString('src="https://www.googletagmanager.com/gtag/js?id=G-1"', $inert);
        $this->assertStringContainsString('window.dataLayer=window.dataLayer||[];', $inert);
        $this->assertStringNotContainsString('<script async', $inert); // the original tag, without the inert attributes
    }

    public function testAnExistingTypeIsReplacedAndRemembered(): void
    {
        $inert = TrackingCode::makeInert('<script type="module" src="/x.js"></script>', 'analytics');

        $this->assertSame(1, substr_count($inert, ' type=')); // data-cmk-type is preceded by "-", not a space
        $this->assertStringContainsString('type="text/plain"', $inert);
        $this->assertStringContainsString('data-cmk-type="module"', $inert);
    }

    public function testPlainJavascriptTypesAreNotRemembered(): void
    {
        $inert = TrackingCode::makeInert('<script type="text/javascript">x()</script>', 'analytics');

        $this->assertStringNotContainsString('data-cmk-type', $inert);
    }

    public function testHostileTypeValuesAreNotCarriedOver(): void
    {
        $inert = TrackingCode::makeInert('<script type="x&quot; onerror=&quot;alert(1)">x()</script>', 'analytics');

        $this->assertStringNotContainsString('onerror', $inert);
        $this->assertStringNotContainsString('data-cmk-type', $inert);
    }

    public function testExistingCmkAttributesCannotOverrideTheCategory(): void
    {
        $inert = TrackingCode::makeInert('<script data-cmk-category="necessary" src="/x.js"></script>', 'marketing');

        $this->assertSame(1, substr_count($inert, 'data-cmk-category='));
        $this->assertStringContainsString('data-cmk-category="marketing"', $inert);
        $this->assertStringNotContainsString('necessary', $inert);
    }

    public function testNoscriptBlocksAreRemoved(): void
    {
        $code = '<script>a()</script><noscript><img src="https://px.example/p.gif" height="1" width="1"></noscript>';

        $inert = TrackingCode::makeInert($code, 'marketing');

        $this->assertStringNotContainsString('noscript', $inert);
        $this->assertStringNotContainsString('px.example', $inert);
    }

    public function testMultilineNoscriptAndUppercaseTagsAreHandled(): void
    {
        $code = "<SCRIPT>a()</SCRIPT>\n<NOSCRIPT>\n<img src=\"https://px.example/p.gif\">\n</NOSCRIPT>";

        $inert = TrackingCode::makeInert($code, 'marketing');

        $this->assertStringNotContainsStringIgnoringCase('noscript', $inert);
        $this->assertStringContainsString('data-cmk-category="marketing"', $inert);
    }

    public function testAnInvalidCategoryDropsTheCodeEntirely(): void
    {
        foreach (['', '<script>', 'Analytics', "analytics\n", 'a b'] as $category) {
            $this->assertSame('', TrackingCode::makeInert('<script>a()</script>', $category), $category);
        }
    }

    public function testCodeWithoutScriptsPassesThrough(): void
    {
        $this->assertSame('<meta name="x" content="y">', TrackingCode::makeInert('<meta name="x" content="y">', 'analytics'));
        $this->assertSame('', TrackingCode::makeInert('', 'analytics'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bypassShapes(): array
    {
        return [
            'slash before type' => ['<script/type=module src=//evil.example/x.js></script>'],
            'type after closing quote' => ['<script src="x"type=module></script>'],
            'category after closing quote' => ['<script src="x"data-cmk-category="necessary"></script>'],
            'double-quoted gt' => ['<script data-x=">" src=//evil.example/a.js></script>'],
            'single-quoted gt' => ["<script data-x='>' src=a.js></script>"],
            'uppercase' => ['<SCRIPT TYPE=module SRC=a.js></SCRIPT>'],
            'newlines' => ["<script\ntype=module\nsrc=a.js></script>"],
            'type inside a value' => ['<script title="a type=module" src=a.js></script>'],
            'self closing' => ['<script/src=a.js/>'],
            'two scripts' => ['<script src=a.js></script><script type=module>b()</script>'],
            'boolean attributes' => ['<script async defer src=a.js></script>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bypassShapes')]
    public function testBypassShapesAreAllRewrittenWithOurAttributesFirst(string $code): void
    {
        $inert = TrackingCode::makeInert($code, 'analytics');
        $ours = '<script type="text/plain" data-cmk-category="analytics"';

        $this->assertNotSame('', $inert);
        $this->assertSame(substr_count(strtolower($inert), '<script'), substr_count($inert, $ours));
        $this->assertStringNotContainsString('data-cmk-category="necessary"', $inert);

        $withoutValues = preg_replace('/"[^"]*"/', '""', $inert);
        $this->assertDoesNotMatchRegularExpression('/\stype=(?!"")/i', $withoutValues);
    }

    public function testAGreaterThanInsideAQuotedValueIsKept(): void
    {
        $this->assertSame(
            '<script type="text/plain" data-cmk-category="analytics" data-x=">" src="//evil.example/a.js"></script>',
            TrackingCode::makeInert('<script data-x=">" src=//evil.example/a.js></script>', 'analytics'),
        );
    }

    public function testDuplicateAttributesKeepOnlyTheFirstAndCmkAttributesAreDropped(): void
    {
        $this->assertSame(
            '<script type="text/plain" data-cmk-category="analytics" data-cmk-type="module" src="a.js"></script>',
            TrackingCode::makeInert('<script type=module src=a.js SRC=b.js data-cmk-foo=1></script>', 'analytics'),
        );
    }

    public function testUnterminatedOpenTagsDropTheWholeSnippet(): void
    {
        $this->assertSame('', TrackingCode::makeInert('<script src="a.js', 'analytics'));
        $this->assertSame('', TrackingCode::makeInert('<script src=a.js', 'analytics'));
        $this->assertSame('', TrackingCode::makeInert('<script>ok()</script><script src="b', 'analytics'));
    }

    public function testAnUnclosedNoscriptIsRemovedToTheEnd(): void
    {
        $inert = TrackingCode::makeInert('<noscript><img src="https://px.example/p.gif">', 'marketing');

        $this->assertStringNotContainsString('px.example', $inert);
        $this->assertStringNotContainsString('<img', $inert);
    }

    public function testAScriptBeforeAnUnclosedNoscriptIsKept(): void
    {
        $inert = TrackingCode::makeInert('<script>a()</script><noscript><img src="https://px.example/p.gif">', 'marketing');

        $this->assertSame('<script type="text/plain" data-cmk-category="marketing">a()</script>', $inert);
    }

    public function testNoscriptWithAttributesAndNoSpaceBeforeSlashIsRemoved(): void
    {
        $inert = TrackingCode::makeInert('<a><noscript/><img src=x></noscript>b<NoScript class=x>y</NOSCRIPT >c', 'marketing');

        $this->assertSame('<a>bc', $inert);
    }

    public function testAVerticalTabIsNotHtmlWhitespace(): void
    {
        $inert = TrackingCode::makeInert("<script\x0Btype=module src=a.js></script>", 'analytics');

        // The browser sees an unknown element named "script\x0Btype=module", not a script, so nothing is rewritten.
        $this->assertStringNotContainsString('<script ', $inert);
        $this->assertStringNotContainsString('<script type', $inert);
    }
}
