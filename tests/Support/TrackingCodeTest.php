<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\TrackingCode;

final class TrackingCodeTest extends TestCase
{
    /**
     * Re-parses the output the way a browser would and checks that nothing can run before consent.
     */
    private function assertAllScriptsInert(string $output, string $category = 'analytics', int $atLeast = 0): void
    {
        $holder = \Dom\HTMLDocument::createEmpty()->createElement('div');
        $holder->innerHTML = $output;

        $scripts = iterator_to_array($holder->querySelectorAll('script'));

        $this->assertGreaterThanOrEqual($atLeast, count($scripts));

        foreach ($scripts as $script) {
            $this->assertSame('text/plain', $script->getAttribute('type'), $output);
            $this->assertSame($category, $script->getAttribute('data-cmk-category'), $output);
        }

        $this->assertSame(0, $holder->querySelectorAll('noscript')->length, $output);
    }

    /**
     * @return list<string> the nonce attribute of every script (null shown as an empty string) after a re-parse
     */
    private function nonces(string $output): array
    {
        $holder = \Dom\HTMLDocument::createEmpty()->createElement('div');
        $holder->innerHTML = $output;
        $found = [];

        foreach ($holder->querySelectorAll('script') as $script) {
            $count = 0;

            foreach ($script->attributes as $attribute) {
                $count += strtolower($attribute->nodeName) === 'nonce' ? 1 : 0;
            }

            $this->assertSame(1, $count, $output);
            $found[] = (string) $script->getAttribute('nonce');
        }

        return $found;
    }

    private function nonces0(string $output): array
    {
        $holder = \Dom\HTMLDocument::createEmpty()->createElement('div');
        $holder->innerHTML = $output;
        $found = [];

        foreach ($holder->querySelectorAll('script') as $script) {
            $this->assertFalse($script->hasAttribute('nonce'), $output);
            $found[] = '';
        }

        return $found;
    }
    /**
     * @return array<string, array{string}>
     */
    public static function bypassShapes(): array
    {
        return [
            'script in comment attribute' => ['<!-- <script a="--><script src=//evil.example/x.js></script>"> -->'],
            'script in script body' => ['<script>x=\'<script a="</script><script src=//evil.example/x.js></script>"></script>'],
            'script in element attribute' => ['<div title=\'<script a="\'><script src=//evil.example/x.js></script>">\'>'],
            'in style' => ['<style><script a="</style><script src=//evil.example/x.js></script>"></style>'],
            'in textarea' => ['<textarea><script a="</textarea><script src=//evil.example/x.js></script>"></textarea>'],
            'in title' => ['<title><script a="</title><script src=//evil.example/x.js></script>"></title>'],
            'in xmp' => ['<xmp><script a="</xmp><script src=//evil.example/x.js></script>"></xmp>'],
            'in iframe' => ['<iframe><script a="</iframe><script src=//evil.example/x.js></script>"></iframe>'],
            'in noembed' => ['<noembed><script a="</noembed><script src=//evil.example/x.js></script>"></noembed>'],
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
            'reassembly' => ['<scr<noscript></noscript>ipt src=evil.js></script>'],
            'unclosed comment' => ['<script src=a.js></script><!-- <script src=b.js></script>'],
            'vertical tab' => ["<script\x0Btype=module src=a.js></script>"],
            'unclosed noscript with script' => ['<noscript><script src=a.js></script>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bypassShapes')]
    public function testBypassShapesLeaveNoLiveScriptWhenReparsed(string $code): void
    {
        $this->assertAllScriptsInert(TrackingCode::makeInert($code, 'analytics'));
    }

    public function testScriptsInsideSvgAndTemplateAreHandled(): void
    {
        // Observed: an SVG script is a script element in the SVG namespace and is still tagged; a template's
        // content is a separate fragment, and its scripts are tagged too so a later clone cannot run them.
        $svg = TrackingCode::makeInert('<svg><script>x</script></svg>', 'analytics');
        $this->assertAllScriptsInert($svg, 'analytics', 1);

        $template = TrackingCode::makeInert('<template><script src=a.js></script></template>', 'analytics');
        $this->assertSame(
            '<template><script src="a.js" type="text/plain" data-cmk-category="analytics"></script></template>',
            $template,
        );
    }

    public function testReassembledScriptRemainsInert(): void
    {
        $this->assertAllScriptsInert(TrackingCode::makeInert('<scr<noscript></noscript>ipt src=evil.js></script>', 'analytics'), 'analytics', 0);
    }

    public function testExternalAndInlineScriptsBecomeInert(): void
    {
        $code = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>'
            . '<script>window.dataLayer=window.dataLayer||[];</script>';

        $inert = TrackingCode::makeInert($code, 'analytics');

        $this->assertAllScriptsInert($inert, 'analytics', 2);
        $this->assertStringContainsString('src="https://www.googletagmanager.com/gtag/js?id=G-1"', $inert);
        $this->assertStringContainsString('window.dataLayer=window.dataLayer||[];', $inert);
    }

    public function testAnExistingTypeIsReplacedAndRemembered(): void
    {
        $inert = TrackingCode::makeInert('<script type="module" src="/x.js"></script>', 'analytics');

        $this->assertAllScriptsInert($inert, 'analytics', 1);
        $this->assertStringContainsString('data-cmk-type="module"', $inert);
        $this->assertSame(1, substr_count($inert, 'type="text/plain"'));
    }

    public function testDuplicateTypeUsesTheFirstOneLikeABrowser(): void
    {
        $inert = TrackingCode::makeInert('<script type=module type=text/javascript src=a.js></script>', 'analytics');

        $this->assertStringContainsString('data-cmk-type="module"', $inert);
    }

    public function testPlainJavascriptTypesAreNotRemembered(): void
    {
        foreach (['text/javascript', 'application/javascript', 'text/plain'] as $type) {
            $inert = TrackingCode::makeInert('<script type="' . $type . '">x()</script>', 'analytics');

            $this->assertStringNotContainsString('data-cmk-type', $inert, $type);
        }
    }

    public function testHostileTypeValuesAreNotCarriedOver(): void
    {
        $inert = TrackingCode::makeInert('<script type="x&quot; onerror=&quot;alert(1)">x()</script>', 'analytics');

        $this->assertStringNotContainsString('onerror', $inert);
        $this->assertStringNotContainsString('data-cmk-type', $inert);
        $this->assertAllScriptsInert($inert, 'analytics', 1);
    }

    public function testExistingCmkAttributesCannotOverrideTheCategory(): void
    {
        $inert = TrackingCode::makeInert('<script data-cmk-category="necessary" data-cmk-type="x" src="/x.js"></script>', 'marketing');

        $this->assertSame(1, substr_count($inert, 'data-cmk-category='));
        $this->assertStringNotContainsString('necessary', $inert);
        $this->assertStringNotContainsString('data-cmk-type', $inert);
        $this->assertAllScriptsInert($inert, 'marketing', 1);
    }

    public function testNoscriptBlocksAreRemoved(): void
    {
        $code = '<script>a()</script><noscript><img src="https://px.example/p.gif" height="1" width="1"></noscript>';

        $inert = TrackingCode::makeInert($code, 'marketing');

        $this->assertStringNotContainsString('noscript', $inert);
        $this->assertStringNotContainsString('px.example', $inert);
        $this->assertAllScriptsInert($inert, 'marketing', 1);
    }

    public function testMultilineNoscriptAndUppercaseTagsAreHandled(): void
    {
        $code = "<SCRIPT>a()</SCRIPT>\n<NOSCRIPT>\n<img src=\"https://px.example/p.gif\">\n</NOSCRIPT>";

        $inert = TrackingCode::makeInert($code, 'marketing');

        $this->assertStringNotContainsStringIgnoringCase('noscript', $inert);
        $this->assertStringNotContainsString('px.example', $inert);
        $this->assertAllScriptsInert($inert, 'marketing', 1);
    }

    public function testAnUnclosedNoscriptRemovesItsContent(): void
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

    public function testANoscriptLookalikeCloseTagDoesNotEndTheNoscript(): void
    {
        $inert = TrackingCode::makeInert('<noscript></noscriptx><img src=https://px.example/p.gif></noscript>', 'marketing');

        $this->assertStringNotContainsString('px.example', $inert);
        $this->assertStringNotContainsString('<img', $inert);
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

    public function testOtherMarkupNextToAScriptIsPreserved(): void
    {
        $inert = TrackingCode::makeInert('<meta name="google-site-verification" content="abc"><script>a()</script>', 'analytics');

        $this->assertSame(
            '<meta name="google-site-verification" content="abc"><script type="text/plain" data-cmk-category="analytics">a()</script>',
            $inert,
        );
    }

    public function testValidNonceIsStampedOnEveryScript(): void
    {
        $code = '<script>a()</script><div><script src="https://x.example/a.js" async></script></div>'
            . '<template><script>t()</script></template><noscript><script>n()</script></noscript>';
        $output = TrackingCode::makeInert($code, 'analytics', 'abcd1234EFGH');

        $this->assertAllScriptsInert($output, 'analytics', 2);
        $this->assertSame(['abcd1234EFGH', 'abcd1234EFGH'], array_slice($this->nonces($output), 0, 2));

        $holder = \Dom\HTMLDocument::createEmpty()->createElement('div');
        $holder->innerHTML = $output;
        $this->assertSame(2, $holder->querySelectorAll('script[nonce="abcd1234EFGH"]')->length);
        $this->assertStringContainsString('nonce="abcd1234EFGH"', $holder->querySelector('template')->innerHTML);
    }

    public function testPastedNonceIsReplaced(): void
    {
        $output = TrackingCode::makeInert('<script nonce="pasted-nonce-1" NONCE="x">a()</script>', 'analytics', 'abcd1234EFGH');

        $this->assertSame(['abcd1234EFGH'], $this->nonces($output));
        $this->assertStringNotContainsString('pasted-nonce-1', $output);
    }

    public function testInvalidOrNullNonceAddsNoneAndDropsPastedOne(): void
    {
        foreach ([null, 'abc', 'abcd 1234efgh', 'abcd"1234efgh', ''] as $nonce) {
            $output = TrackingCode::makeInert('<script nonce="pasted-nonce-1">a()</script><script>b()</script>', 'analytics', $nonce);

            $this->assertAllScriptsInert($output, 'analytics', 2);
            $this->assertSame(['', ''], $this->nonces0($output), (string) $nonce);
            $this->assertStringNotContainsString('pasted-nonce-1', $output);
        }
    }
}
