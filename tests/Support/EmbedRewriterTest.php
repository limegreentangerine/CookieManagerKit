<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\EmbedRewriter;
use PHPUnit\Framework\Attributes\DataProvider;

final class EmbedRewriterTest extends TestCase
{
    private const SERVICES = [
        ['name' => 'YouTube', 'category' => 'marketing', 'categoryTitle' => 'Marketing', 'domains' => ['youtube.com', 'www.youtube-nocookie.com']],
        ['name' => 'Maps', 'category' => 'preferences', 'categoryTitle' => 'Preferences', 'domains' => ['*.google.com']],
    ];
    private const TEXT = ['message' => 'Provided by {service}.', 'accept' => 'Accept {category} cookies', 'customise' => 'Customise'];

    private const MARKETING = 'data-cmk-embed=marketing';

    /** @var list<\Throwable> */
    private array $errors = [];

    private function rewrite(string $html): string
    {
        $this->errors = [];

        return EmbedRewriter::rewrite($html, self::SERVICES, self::TEXT, function (\Throwable $e): void {
            $this->errors[] = $e;
        });
    }


    /**
     * Belt and braces: the Layer 1 superset scan over the output bytes, then a real HTML5 parse of the output.
     */
    private function assertNoLiveMatchedFrame(string $html): void
    {
        $this->assertSame([], EmbedRewriter::audit($html, self::SERVICES), 'a matched iframe start tag still has a src');

        $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);

        foreach ($doc->querySelectorAll('iframe') as $iframe) {
            $src = $iframe->getAttribute('src');

            $this->assertTrue($src === null || EmbedRewriter::match($src, self::SERVICES) === null, 'A matched iframe is live: ' . $src);
        }
    }

    private function assertEveryIframeHasSrcdoc(string $html): void
    {
        $this->assertSame(substr_count(strtolower($html), '<iframe srcdoc'), preg_match_all('/<iframe(?=[\t\n\f\r \/>])/i', $html), 'an iframe is missing srcdoc');
    }

    #[DataProvider('matching')]
    public function testHostsMatchOnlyTheDomainOrItsSubdomains(string $url, ?string $expected): void
    {
        $match = EmbedRewriter::match($url, self::SERVICES);

        $this->assertSame($expected, $match['name'] ?? null);
    }

    public static function matching(): iterable
    {
        yield 'exact' => ['https://youtube.com/embed/x', 'YouTube'];
        yield 'subdomain' => ['https://www.youtube.com/embed/x', 'YouTube'];
        yield 'uppercase' => ['HTTPS://WWW.YouTube.COM/embed/x', 'YouTube'];
        yield 'trailing dot' => ['https://youtube.com./embed/x', 'YouTube'];
        yield 'wildcard domain, subdomain' => ['https://maps.google.com/embed', 'Maps'];
        yield 'wildcard domain, the base host' => ['https://google.com/embed', 'Maps'];
        yield 'lookalike suffix' => ['https://evilyoutube.com/embed/x', null];
        yield 'domain as a subdomain of another host' => ['https://youtube.com.evil.com/embed/x', null];
        yield 'userinfo trick' => ['https://youtube.com@evil.com/embed/x', null];
        yield 'path only' => ['https://evil.com/youtube.com', null];
        yield 'not http' => ['ftp://youtube.com/x', null];
        yield 'javascript' => ['javascript:alert(1)', null];
        yield 'relative' => ['/embed/x', null];
        yield 'empty' => ['', null];
    }

    public function testAMatchedIframeGetsAPlaceholderAndAnUnloadedFrame(): void
    {
        $out = $this->rewrite('<p>Hi</p><iframe width="560" height="315" src="https://www.youtube.com/embed/abc" title="A > B" allowfullscreen></iframe><p>Bye</p>');

        $this->assertSame(
            '<p>Hi</p><span class="cmk-embed" style="width:560px;min-height:315px;"><span class="cmk-embed__box" data-cmk-embed-placeholder>'
            . '<span class="cmk-embed__message">Provided by YouTube.</span><span class="cmk-embed__actions">'
            . '<button type="button" class="cmk-embed__accept" data-cmk-embed-accept="marketing">Accept Marketing cookies</button> '
            . '<button type="button" class="cmk-embed__prefs" data-cmk-open>Customise</button></span></span>'
            . '<iframe data-cmk-embed=marketing width="560" height="315" data-cmk-src="https://www.youtube.com/embed/abc" title="A > B" allowfullscreen></iframe></span><p>Bye</p>',
            $out,
        );
        $this->assertSame([], $this->errors);
        $this->assertNoLiveMatchedFrame($out);
    }

    public function testNoStyleIsEmittedForNonNumericSizes(): void
    {
        $out = $this->rewrite('<iframe src="https://youtube.com/x" width="100%;position:fixed" height="calc(1px)"></iframe>');

        $this->assertStringNotContainsString('style=', $out);
        $this->assertStringContainsString('data-cmk-embed-placeholder', $out);
    }

    public function testUnmatchedAndSrcdocAndSourcelessAndNonHttpIframesAreByteIdentical(): void
    {
        $html = '<iframe src="https://example.org/x"></iframe><iframe srcdoc="<p>hi</p>" src="https://youtube.com/x"></iframe><iframe></iframe><iframe src="/local"></iframe>'
            . '<iframe src="javascript:alert(1)"></iframe><iframe src="data:text/html,youtube.com"></iframe><iframe src></iframe>';

        $this->assertSame($html, $this->rewrite($html));
    }

    public function testQuotesSingleQuotedAndUnquotedAttributesAreHandled(): void
    {
        $out = $this->rewrite("<IFRAME SRC='https://youtube.com/embed/1' class=video data-x=\"1\"></IFRAME>");

        $this->assertStringContainsString("<IFRAME data-cmk-embed=marketing data-cmk-src='https://youtube.com/embed/1' class=video data-x=\"1\"></IFRAME></span>", $out);
    }

    public function testASourceWithoutAProtocolIsTreatedAsHttps(): void
    {
        $this->assertStringContainsString('data-cmk-src="//www.youtube.com/e"', $this->rewrite('<iframe src="//www.youtube.com/e"></iframe>'));
    }

    public function testSeveralIframesAreAllRewrittenIndependently(): void
    {
        $out = $this->rewrite('<iframe src="https://youtube.com/a"></iframe>X<iframe src="https://example.org/b"></iframe>Y<iframe src="https://maps.google.com/c"></iframe>');

        $this->assertSame(2, substr_count($out, 'data-cmk-embed-placeholder'));
        $this->assertStringContainsString('data-cmk-embed=preferences', $out);
        $this->assertStringContainsString('X<iframe src="https://example.org/b"></iframe>Y', $out);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function ambiguousContexts(): iterable
    {
        $in = '<iframe src="https://youtube.com/x"></iframe>';
        $out = '<iframe data-cmk-embed=marketing data-cmk-src="https://youtube.com/x"></iframe>';

        yield 'script string' => ["<script>document.write('" . $in . "')</script>", "<script>document.write('" . $out . "')</script>"];
        yield 'style' => ['<style>/* ' . $in . ' */</style>', '<style>/* ' . $out . ' */</style>'];
        yield 'comment' => ['<!-- ' . $in . ' -->', '<!-- ' . $out . ' -->'];
        yield 'unterminated comment' => ['<!-- ' . $in, '<!-- ' . $out];
        yield 'textarea' => ['<textarea>' . $in . '</textarea>', '<textarea>' . $out . '</textarea>'];
        yield 'title' => ['<title>' . $in . '</title>', '<title>' . $out . '</title>'];
        yield 'attribute value' => ['<div title="<iframe src=https://youtube.com/x>">hello</div><iframe src="https://example.org/"></iframe>', '<div title="<iframe data-cmk-embed=marketing data-cmk-src=https://youtube.com/x>">hello</div><iframe src="https://example.org/"></iframe>'];
        yield 'self-closing slash' => ['<script>"<iframe/src=https://youtube.com/x>"</script>', '<script>"<iframe data-cmk-embed=marketing /data-cmk-src=https://youtube.com/x>"</script>'];
    }

    #[DataProvider('ambiguousContexts')]
    public function testAmbiguousContextsGetTheBlockingLayerOnly(string $html, string $expected): void
    {
        $out = $this->rewrite($html);

        $this->assertSame($expected, $out);
        $this->assertStringNotContainsString('cmk-embed__', $out);
        $this->assertNoLiveMatchedFrame($out);
    }

    public function testAnIframeAfterARawTextRegionIsStillGivenAPlaceholder(): void
    {
        $out = $this->rewrite('<script>var a = 1;</script><iframe src="https://youtube.com/x"></iframe>');

        $this->assertStringStartsWith('<script>var a = 1;</script><span class="cmk-embed">', $out);
        $this->assertStringEndsWith('</iframe></span>', $out);
    }

    public function testAnUnterminatedMatchedIframeIsBlockedWithoutAWrapper(): void
    {
        $out = $this->rewrite('<p>A</p><iframe src="https://youtube.com/x"><p>B</p>');

        $this->assertSame('<p>A</p><iframe data-cmk-embed=marketing data-cmk-src="https://youtube.com/x"><p>B</p>', $out);
    }

    public function testAnUnterminatedUnmatchedIframeIsLeftAlone(): void
    {
        $html = '<iframe src="https://example.org/x"><p>B</p>';

        $this->assertSame($html, $this->rewrite($html));
        $this->assertSame([], $this->errors);
    }

    public function testHostileTextAndAttributesAreEscapedAndExistingMarkersDisarmed(): void
    {
        $services = [['name' => '<script>x</script>', 'category' => 'marketing', 'categoryTitle' => '"><img src=x>', 'domains' => ['youtube.com']]];
        $out = EmbedRewriter::rewrite(
            '<iframe src="https://youtube.com/x" title=\'"><script>bad()</script>\' onload="evil()" data-cmk-src="https://evil.example/" data-cmk-embed="x"></iframe>',
            $services,
            ['message' => '{service}', 'accept' => '{category}', 'customise' => '<b>c</b>'],
            static function (\Throwable $e): void {},
        );
        $doc = \Dom\HTMLDocument::createFromString($out, LIBXML_NOERROR);

        $this->assertSame(0, $doc->querySelectorAll('script, img, b')->length);
        $this->assertSame(1, substr_count($out, 'data-cmk-src='));
        $this->assertSame(1, substr_count($out, 'data-cmk-embed='));
        $this->assertStringContainsString('data-cmk_src="https://evil.example/"', $out);
        $this->assertStringContainsString('data-cmk_embed="x"', $out);
        $this->assertSame([], EmbedRewriter::audit($out, $services));
    }

    public function testAPageWithoutIframesOrServicesIsReturnedUntouched(): void
    {
        $this->assertSame('<p>x</p>', $this->rewrite('<p>x</p>'));
        $this->assertSame('<iframe src="https://youtube.com/x"></iframe>', EmbedRewriter::rewrite('<iframe src="https://youtube.com/x"></iframe>', [], self::TEXT, static function (\Throwable $e): void {}));
    }

    public function testALookalikeElementIsNotAnIframe(): void
    {
        $html = '<iframe-x src="https://youtube.com/x"></iframe-x><iframes src="https://youtube.com/x"></iframes>';

        $this->assertSame($html, $this->rewrite($html));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function attacks(): iterable
    {
        $f = '<iframe src="https://youtube.com/x"></iframe>';

        yield 'C1 empty comment' => ['<!-->' . $f . '<!-- x -->'];
        yield 'C1 dash comment' => ['<!--->' . $f . '<!-- x -->'];
        yield 'C1 bang comment close' => ['<!-- x --!>' . $f];
        yield 'C1 script end tag with attr' => ['<script></script x>' . $f];
        yield 'C1 script end tag slash' => ['<script></script/>' . $f];
        yield 'C1 bogus comment pi' => ['<? <script> ?>' . $f];
        yield 'C1 bogus comment bang' => ['<!x <script>>' . $f];
        yield 'C1 end tag attr' => ['</p title="<script>">' . $f];
        yield 'C1 textarea' => ['<textarea><script></textarea>' . $f];
        yield 'C1 title' => ['<title><!--</title>' . $f];
        yield 'C1 iframe fallback' => ['<iframe src="https://example.org/"><script></iframe>' . $f];
        yield 'C1 quote in unquoted value' => ['<a title=x\'><b title="\'><script>">' . $f];
        yield 'C1 noscript attribute' => ['<noscript><p title="</noscript><iframe src=https://youtube.com/x></iframe>"></p></noscript>'];
        yield 'C1 noscript comment' => ['<noscript><!-- </noscript><iframe src="https://youtube.com/x"></iframe> --></noscript>'];
        yield 'I3 svg style' => ['<svg></p><style><a id="</style><iframe src="https://youtube.com/x"></iframe>">'];
        yield 'I3 select' => ['<select><iframe src="https://youtube.com/x"></iframe></select>'];
        yield 'I3 declarative shadow dom' => ['<div><template shadowrootmode="open"><iframe src="https://youtube.com/x"></iframe></template></div>'];
        yield 'C2 quote in unquoted' => ['<iframe title=a"src=x" src="https://youtube.com/x"></iframe>'];
        yield 'C2 lt in unquoted' => ['<iframe title=a<src=x src="https://youtube.com/x"></iframe>'];
        yield 'C2 equals in unquoted' => ['<iframe title=a=src=x src="https://youtube.com/x"></iframe>'];
        yield 'C2 equals first in name' => ['<iframe =src="a" src="https://youtube.com/x"></iframe>'];
        yield 'C2 slash separated' => ['<iframe/src="https://youtube.com/x"></iframe>'];
        yield 'C2 duplicate src' => ['<iframe src="https://youtube.com/x" src="https://example.org/"></iframe>'];
        yield 'C2 uppercase and spaces' => ['<IFRAME  SRC = "https://youtube.com/x" ></IFRAME>'];
        yield 'C3 entity tab' => ['<iframe src="https://you&Tab;tube.com/x"></iframe>'];
        yield 'C3 entity newline' => ['<iframe src="https://you&NewLine;tube.com/x"></iframe>'];
        yield 'C3 literal newline' => ["<iframe src=\"https://you\ntube.com/x\"></iframe>"];
        yield 'C3 entity without semicolon' => ['<iframe src="https://&#121outube.com/x"></iframe>'];
        yield 'C3 hex entity' => ['<iframe src="https://&#x79;outube.com/x"></iframe>'];
        yield 'C3 backslashes' => ['<iframe src="https:\\\\youtube.com\\x"></iframe>'];
        yield 'C3 slash backslash' => ['<iframe src="/\\youtube.com/x"></iframe>'];
        yield 'C3 triple slash' => ['<iframe src="https:///youtube.com/x"></iframe>'];
        yield 'C3 percent host' => ['<iframe src="https://%79outube.com/x"></iframe>'];
        yield 'C3 fullwidth' => ['<iframe src="https://ｙoutube.com/x"></iframe>'];
        yield 'C3 leading controls' => ["<iframe src=\" \x01https://youtube.com/x\"></iframe>"];
        yield 'C3 port and userinfo' => ['<iframe src="https://user:pw@youtube.com:443/x"></iframe>'];
        yield 'C3 trailing dots' => ['<iframe src="https://youtube.com../x"></iframe>'];
        yield 'CA legacy shy' => ['<iframe src="https://youtube&shy.com/embed/x"></iframe>'];
        yield 'CA padded hex' => ['<iframe src="https://&#x0000000079;outube.com/x"></iframe>'];
        yield 'CA padded decimal' => ['<iframe src="https://&#00000000121;outube.com/x"></iframe>'];
        yield 'CA padded decimal no semicolon' => ['<iframe src="https://&#000000000121outube.com/x"></iframe>'];
        yield 'IA noscript script' => ['<noscript><p title=\'</noscript><script>x="\'></p></noscript><iframe src=https://youtube.com/x></iframe>";</script>'];
        yield 'I2 close tag in comment' => ['<iframe src="https://youtube.com/x"><!-- </iframe> --></iframe>hello'];
        yield 'script string' => ['<script>document.write(\'<iframe src="https://youtube.com/x"></iframe>\')</script>'];
        yield 'comment' => ['<!-- ' . $f . ' -->'];
        yield 'textarea plain' => ['<textarea>' . $f . '</textarea>'];
    }

    #[DataProvider('attacks')]
    public function testAttackInputsNeverLeaveAMatchedIframeLive(string $html): void
    {
        $out = $this->rewrite($html);

        $this->assertStringContainsString(self::MARKETING, $out);
        $this->assertNoLiveMatchedFrame($out);
        $this->assertSame($out, $this->rewrite($out), 'rewriting twice must change nothing');
    }

    public function testAttributeSmugglingChangesOnlyTheInsertedTokens(): void
    {
        $html = '<div title="<iframe src=https://youtube.com/x>">hello</div><iframe src="https://example.org/"></iframe>';
        $out = $this->rewrite($html);

        $this->assertStringContainsString('hello</div><iframe src="https://example.org/"></iframe>', $out);
        $this->assertSame($html, str_replace([' data-cmk-embed=marketing', 'data-cmk-src'], ['', 'src'], $out));
    }

    public function testLookalikeHostsAreNotRewrittenEvenWhenSmuggled(): void
    {
        $html = '<iframe src="https://evilyoutube.com/x"></iframe><iframe src="https://youtube.com.evil.com/x"></iframe><iframe src="https://youtube.com@evil.com/x"></iframe><iframe src="https://evil.com/?u=youtube.com"></iframe>';

        $this->assertSame($html, $this->rewrite($html));
    }

    public function testAnUnparseableHostThatNamesAServiceIsBlocked(): void
    {
        $this->assertStringContainsString(self::MARKETING, $this->rewrite('<iframe src="https://[youtube.com]/x"></iframe>'));
    }

    public function testTheHeadNoscriptPixelIsNotMovedWhenAnEmbedIsRewritten(): void
    {
        $head = '<html><head><noscript><img src="https://www.facebook.com/tr?id=1&ev=PageView"/></noscript></head><body>';
        $out = $this->rewrite($head . '<iframe src="https://youtube.com/embed/ok"></iframe></body></html>');

        $this->assertStringStartsWith($head . '<span class="cmk-embed">', $out);
        $this->assertStringEndsWith('<iframe data-cmk-embed=marketing data-cmk-src="https://youtube.com/embed/ok"></iframe></span></body></html>', $out);
    }

    public function testAnUnmatchedGtmNoscriptFrameInTheHeadIsUntouched(): void
    {
        $html = '<html><head><noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-X" height="0" width="0" style="display:none"></iframe></noscript></head><body><p>x</p></body></html>';

        $this->assertSame($html, $this->rewrite($html));
    }

    public function testFragmentsDoctypesAndOddBytesPassThroughUntouched(): void
    {
        $prefix = "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 4.01//EN\">\xE9 &copy; <br>\n";
        $out = $this->rewrite($prefix . '<iframe src="https://youtube.com/x"></iframe>' . "\xFF<unclosed");

        $this->assertStringStartsWith($prefix . '<span class="cmk-embed">', $out);
        $this->assertStringEndsWith("</iframe></span>\xFF<unclosed", $out);
        $this->assertStringNotContainsString('<html', $out);
    }

    public function testPagesWithOnlyUnmatchedIframesAreByteIdenticalEvenWithWeirdMarkup(): void
    {
        $html = "<p>unclosed<div><IFRAME src=\"https://example.org/\"></IFRAME><b><i></b></i><!-- x --><table><td>\xC3\xA9";

        $this->assertSame($html, $this->rewrite($html));
    }

    public function testRewritingTheOutputAgainChangesNothing(): void
    {
        $once = $this->rewrite('<p>Hi</p><iframe src="https://youtube.com/x" width="10"></iframe>');

        $this->assertSame($once, $this->rewrite($once));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function referenceTricks(): iterable
    {
        yield 'legacy named reference without semicolon' => ['<iframe src="https://youtube&shy.com/embed/x"></iframe>'];
        yield 'padded hex' => ['<iframe src="https://&#x0000000079;outube.com/x"></iframe>'];
        yield 'padded decimal' => ['<iframe src="https://&#00000000121;outube.com/x"></iframe>'];
        yield 'padded decimal without semicolon' => ['<iframe src="https://&#000000000121outube.com/x"></iframe>'];
        yield 'named with semicolon' => ['<iframe src="https://youtube&shy;.com/x"></iframe>'];
        yield 'zero width' => ['<iframe src="https://you&#x200B;tube.com/x"></iframe>'];
    }

    #[DataProvider('referenceTricks')]
    public function testCharacterReferenceTricksAreDecodedLikeABrowser(string $html): void
    {
        $out = $this->rewrite($html);

        $this->assertStringContainsString(self::MARKETING, $out);
        $this->assertSame([], EmbedRewriter::audit($out, self::SERVICES));
        $this->assertDoesNotMatchRegularExpression('/<iframe[^>]*\ssrc=/i', $out);
    }

    public function testAReferenceIsDecodedOnlyOnce(): void
    {
        $html = '<iframe src="https://&#38;#121;outube.com/x"></iframe><iframe src="https://youtube&shyx.com/x"></iframe><iframe src="https://youtube.com&amp=1@evil.com/x"></iframe>';

        $this->assertSame($html, $this->rewrite($html));
    }

    public function testServicesNamedOnlyInAQueryStringOrPathAreNotMistakenForTheHost(): void
    {
        $html = '<iframe src="https://example.org/?r=https://youtube.com/x"></iframe><iframe src="https://evil.com/youtube.com"></iframe><iframe src="https://evil.com/?u=youtube.com"></iframe>';

        $this->assertSame($html, $this->rewrite($html));
        $this->assertSame([], $this->errors);
    }

    public function testANoscriptScriptDisagreementNeverPutsQuotesInsideAScriptString(): void
    {
        $html = '<noscript><p title=\'</noscript><script>x="\'></p></noscript><iframe src=https://youtube.com/x></iframe>";</script>';

        $this->assertSame(
            '<noscript><p title=\'</noscript><script>x="\'></p></noscript><iframe data-cmk-embed=marketing data-cmk-src=https://youtube.com/x></iframe>";</script>',
            $this->rewrite($html),
        );
    }

    public function testNoPlaceholderFollowsAScriptThatOpensAnHtmlComment(): void
    {
        $out = $this->rewrite('<script><!-- document.write("<script>") </script><iframe src="https://youtube.com/x"></iframe> --></script>');

        $this->assertStringContainsString(self::MARKETING, $out);
        $this->assertStringNotContainsString('cmk-embed__', $out);
    }

    public function testHostIsReadLikeABrowserReadsIt(): void
    {
        $this->assertSame('youtube.com', EmbedRewriter::host("  https://You\tTube.com:8080/x"));
        $this->assertNull(EmbedRewriter::host('https://exa mple.com/'));
    }

    public function testFailClosedInsertsSrcdocAndChangesNothingElse(): void
    {
        $html = '<iframe src="https://youtube.com/x" title=">"><script>alert(1)</script></iframe><IFRAME/SRC=//example.org/><iframe>x</iframe><iframe-x src=a><iframe';
        $out = EmbedRewriter::failClosed($html);

        $this->assertSame('<iframe srcdoc src="https://youtube.com/x" title=">"><script>alert(1)</script></iframe><IFRAME srcdoc/SRC=//example.org/><iframe srcdoc>x</iframe><iframe-x src=a><iframe', $out);
        $this->assertSame($out, EmbedRewriter::failClosed($out), 'idempotent');
        $this->assertSame('', EmbedRewriter::failClosed(''));
    }

    public function testFailClosedSkipsAnIframeThatAlreadyStartsWithSrcdoc(): void
    {
        $html = '<iframe srcdoc="a" src="https://youtube.com/x"></iframe><iframe/srcdoc src=b></iframe><iframe srcdocx></iframe>';

        $this->assertSame('<iframe srcdoc="a" src="https://youtube.com/x"></iframe><iframe/srcdoc src=b></iframe><iframe srcdoc srcdocx></iframe>', EmbedRewriter::failClosed($html));
    }

    public function testNeutralisingNeverTurnsInertIframeContentIntoMarkup(): void
    {
        $html = '<!-- ' . str_repeat("<iframe title='", 2000) . ' --><iframe src="https://example.org/"><script>alert(document.domain)</script></iframe>';
        $out = $this->rewrite($html);

        $this->assertSame(str_replace('<iframe', '<iframe srcdoc', $html), $out);
        $this->assertStringNotContainsString('cmk-blocked', $out);
        $this->assertNotSame([], $this->errors);
    }

    public function testAnAttackerForcedFallbackIsBlockedWithSrcdoc(): void
    {
        $html = '<!-- ' . str_repeat("<iframe title='", 600) . ' --><iframe a"=x title="y>z" src=https://youtube.com/x></iframe>';
        $out = $this->rewrite($html);

        $this->assertSame(str_replace('<iframe', '<iframe srcdoc', $html), $out);
    }

    public function testStressInputsAreCappedQuicklyAndNeverLeftLive(): void
    {
        $inputs = [
            'many matched' => str_repeat('<iframe src="https://youtube.com/x"></iframe>', 40000),
            'no tag ends' => str_repeat('<iframe ', 260000),
            'quote trap' => str_repeat('<iframe src="https://youtube.com/x" a="', 52000) . '">',
        ];

        foreach ($inputs as $name => $html) {
            $start = microtime(true);
            $out = $this->rewrite($html);

            $this->assertLessThan(2.0, microtime(true) - $start, $name);
            $this->assertNotSame('', $out, $name);
            $this->assertNotSame([], $this->errors, $name);
            $this->assertEveryIframeHasSrcdoc($out);
        }
    }

    public function testAPageOverFourMegabytesIsBlockedWithoutParsing(): void
    {
        $out = $this->rewrite('<iframe src="https://youtube.com/x"></iframe>' . str_repeat('a', 4_200_000));

        $this->assertStringStartsWith('<iframe srcdoc src=', $out);
        $this->assertNotSame([], $this->errors);
    }

    public function testBrokenPcreNeverBlanksOrLeavesAFrameLive(): void
    {
        $html = '<p>keep me</p>' . str_repeat('<iframe src="https://youtube.com/x" title="t"></iframe>', 200);
        $limit = ini_set('pcre.backtrack_limit', '1');
        $jit = ini_set('pcre.jit', '0');

        try {
            $out = $this->rewrite($html);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
            ini_set('pcre.jit', (string) $jit);
        }

        $this->assertNotSame([], $this->errors);
        $this->assertStringContainsString('<p>keep me</p>', $out);
        $this->assertEveryIframeHasSrcdoc($out);
    }

    public function testAFailingErrorHandlerNeverEscapes(): void
    {
        $out = EmbedRewriter::rewrite(str_repeat('<iframe src="https://youtube.com/x"></iframe>', 1500), self::SERVICES, self::TEXT, static function (\Throwable $e): void {
            throw new \RuntimeException('handler failed');
        });

        $this->assertEveryIframeHasSrcdoc($out);
    }

    public function testAnIframeWhoseTagNeverEndsIsNeverLeftLive(): void
    {
        $out = $this->rewrite('<p>A</p><iframe src="https://youtube.com/x" title="oops></iframe><p>B</p>');

        $this->assertSame('<p>A</p><iframe srcdoc src="https://youtube.com/x" title="oops></iframe><p>B</p>', $out);
    }

    public function testAnApostropheInTextDoesNotEscalateTheWholePage(): void
    {
        $out = $this->rewrite('<iframe src="https://www.youtube.com/embed/a" title="Today\'s video"></iframe><script src="https://www.youtube.com/iframe_api"></script>');

        $this->assertStringContainsString('data-cmk-embed-placeholder', $out);
        $this->assertStringEndsWith('</span><script src="https://www.youtube.com/iframe_api"></script>', $out);
        $this->assertSame([], $this->errors);
    }

    public function testAServiceNamedInAQueryStringOfAnotherHostIsLeftAlone(): void
    {
        $html = '<iframe class="embedly-embed" src="//cdn.embedly.com/widgets/media.html?src=https%3A%2F%2Fwww.youtube.com%2Fembed%2Fabc&amp;display_name=YouTube" width="640" height="360"></iframe>';

        $this->assertSame($html, $this->rewrite($html));
        $this->assertSame([], $this->errors);
    }

    public function testSrcInsideAnotherAttributeValueIsNotASource(): void
    {
        $html = '<iframe title="src=https://youtube.com/" src="https://example.org/"></iframe>';

        $this->assertSame($html, $this->rewrite($html));
        $this->assertSame([], $this->errors);
    }

    public function testABareSrcdocMeansNothingLoadsAndNothingEscalates(): void
    {
        $html = '<iframe srcdoc src="https://youtube.com/x"></iframe><iframe src="https://youtube.com/x" srcdoc></iframe>';

        $this->assertSame($html, $this->rewrite($html));
        $this->assertSame([], $this->errors);
    }

    public function testADuplicateSourceWhoseFirstIsUnmatchedDoesNotEscalate(): void
    {
        $html = '<iframe src="https://example.org/" src="https://youtube.com/y"></iframe>';

        $this->assertSame($html, $this->rewrite($html));
        $this->assertSame([], $this->errors);
    }

    public function testTheCoarseCheckAloneFlagsALiveSourceAndNotLookalikes(): void
    {
        $coarse = new \ReflectionMethod(EmbedRewriter::class, 'coarse');

        $this->assertSame([0], $coarse->invoke(null, '<iframe src="https://youtube.com/x"></iframe>', self::SERVICES));
        $this->assertSame([0], $coarse->invoke(null, '<iframe src=&#x68;ttps://you&shy;tube.com/x></iframe>', self::SERVICES));

        foreach (['https://evilyoutube.com/x', 'https://youtube.com.evil.com/x', 'https://youtube.com@evil.com/x', 'https://evil.com/?u=youtube.com', 'https://evil.com/youtube.com'] as $url) {
            $this->assertSame([], $coarse->invoke(null, '<iframe src="' . $url . '"></iframe>', self::SERVICES), $url);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ignoredCharacters(): iterable
    {
        $points = ['115F' => "\u{115F}", '1160' => "\u{1160}", '3164' => "\u{3164}", 'FFA0' => "\u{FFA0}", '17B4' => "\u{17B4}", '17B5' => "\u{17B5}", '180E' => "\u{180E}", '206A' => "\u{206A}", '1D173' => "\u{1D173}", '1D17A' => "\u{1D17A}", '2064' => "\u{2064}"];

        foreach ($points as $hex => $char) {
            yield "U+$hex numeric" => ['<iframe src="https://www.you&#x' . $hex . ';tube.com/embed/x"></iframe>'];
            yield "U+$hex literal" => ['<iframe src="https://www.you' . $char . 'tube.com/embed/x"></iframe>'];
            yield "U+$hex percent" => ['<iframe src="https://www.you' . rawurlencode($char) . 'tube.com/embed/x"></iframe>'];
        }

        yield 'double hyphen label' => ['<iframe src="https://ab--cd.ｙoutube.com/x"></iframe>'];
        yield 'ideographic dot' => ['<iframe src="https://www.you&#x115F;tube。com/x"></iframe>'];
    }

    #[DataProvider('ignoredCharacters')]
    public function testHostsWithCharactersABrowserIgnoresAreStillBlocked(string $html): void
    {
        $out = $this->rewrite($html);

        $this->assertStringContainsString(self::MARKETING, $out);
        $this->assertSame([], EmbedRewriter::audit($out, self::SERVICES));
        $this->assertSame($out, $this->rewrite($out), 'idempotent');
    }

    /**
     * ICU-dependent: on old ICU these hosts fail validation and go through guess(); on new ICU they validate. Results
     * must be identical under both (run the suite with each PHP build).
     *
     * @return iterable<string, array{string}>
     */
    public static function ignoredCharacterLookalikes(): iterable
    {
        $f = "\u{115F}";

        yield 'filler between evil and youtube' => ['<iframe src="https://evil' . $f . 'youtube.com/x"></iframe>'];
        yield 'filler before dot' => ['<iframe src="https://youtube.com' . $f . '.evil.com/x"></iframe>'];
        yield 'filler inside tld' => ['<iframe src="https://youtube.c' . $f . 'om.evil.com/x"></iframe>'];
        yield 'plain lookalike' => ['<iframe src="https://evilyoutube.com/x"></iframe>'];
        yield 'plain suffix' => ['<iframe src="https://youtube.com.evil.com/x"></iframe>'];
        yield 'userinfo' => ['<iframe src="https://youtube.com@evil.com/x"></iframe>'];
        yield 'userinfo with filler' => ['<iframe src="https://youtube.com' . $f . '@evil.com/x"></iframe>'];
        yield 'filler in path' => ['<iframe src="https://evil.com/you' . $f . 'tube.com"></iframe>'];
    }

    #[DataProvider('ignoredCharacterLookalikes')]
    public function testLookalikesWithIgnoredCharactersAreNotBlocked(string $html): void
    {
        $this->assertSame($html, $this->rewrite($html));
        $this->assertSame([], $this->errors);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outlinedLetters(): iterable
    {
        $cases = [
            '1CCE2' => 'https://www.youtube.co%s/embed/x',
            '1CCEE' => 'https://www.%soutube.com/embed/x',
            '1CCE9' => 'https://www.you%sube.com/embed/x',
        ];

        foreach ($cases as $hex => $format) {
            $char = (string) mb_chr((int) hexdec($hex), 'UTF-8');

            yield "U+$hex numeric" => ['<iframe src="' . sprintf($format, '&#x' . $hex . ';') . '"></iframe><p>x</p>'];
            yield "U+$hex literal" => ['<iframe src="' . sprintf($format, $char) . '"></iframe><p>x</p>'];
            yield "U+$hex percent" => ['<iframe src="' . sprintf($format, rawurlencode($char)) . '"></iframe><p>x</p>'];
        }
    }

    /**
     * Holds under old ICU (unfoldable, whole page fails closed) and new ICU (folds, placeholder or fail closed).
     */
    #[DataProvider('outlinedLetters')]
    public function testUnicodeSixteenLettersNeverLeaveALiveMatchedFrame(string $html): void
    {
        $out = $this->rewrite($html);

        $this->assertNotSame('', $out);
        $this->assertStringContainsString('<p>x</p>', $out);
        $this->assertSame([], EmbedRewriter::audit($out, self::SERVICES));
        $this->assertTrue(str_contains($out, self::MARKETING) || str_contains($out, '<iframe srcdoc'), 'blocked one way or the other');
    }

    public function testAnUnassignedCodePointInAHostFailsTheWholePageClosed(): void
    {
        $html = '<p>a</p><iframe src="https://www.youtube.co&#x0378;/embed/x"></iframe><iframe src="https://example.org/"></iframe>';
        $out = $this->rewrite($html);

        $this->assertSame(substr_count($html, '<iframe'), substr_count($out, '<iframe srcdoc'));
        $this->assertNotSame([], $this->errors);
        $this->assertSame('unfoldable host', $this->errors[0]->getMessage());
        $this->assertStringContainsString('<p>a</p>', $out);
    }

    public function testWithoutIntlANonAsciiHostThatFailsValidationFailsClosed(): void
    {
        $flag = new \ReflectionProperty(EmbedRewriter::class, 'withoutIntl');
        $flag->setValue(null, true);

        try {
            $out = $this->rewrite("<iframe src=\"https://www.\u{FF59}outube.com/embed/x\"></iframe><p>x</p>");
        } finally {
            $flag->setValue(null, false);
        }

        $this->assertStringContainsString('<iframe srcdoc', $out);
        $this->assertStringContainsString('<p>x</p>', $out);
        $this->assertSame('unfoldable host', $this->errors[0]->getMessage());
    }

    public function testHostsThatValidateAreUntouched(): void
    {
        foreach (["<iframe src=\"https://m\u{FC}nchen.de/x\"></iframe>", "<iframe src=\"https://www.y\u{F6}utube.com/x\"></iframe>"] as $html) {
            $this->assertSame($html, $this->rewrite($html));
            $this->assertSame([], $this->errors);
        }
    }

    public function testAssertFoldableAcceptsAsciiAndDefinedCharactersOnly(): void
    {
        $check = new \ReflectionMethod(EmbedRewriter::class, 'assertFoldable');
        $check->invoke(null, 'youtube.com', false, false);
        $check->invoke(null, "a\u{115F}b", true, true);

        foreach ([["a\u{0378}b", true, true], ["a\u{115F}b", false, true], ["a\u{115F}b", true, false], ["a\xFFb", true, true]] as [$host, $n, $i]) {
            $message = null;

            try {
                $check->invoke(null, $host, $n, $i);
            } catch (\RuntimeException $e) {
                $message = $e->getMessage();
            }

            $this->assertSame('unfoldable host', $message);
        }
    }
}
