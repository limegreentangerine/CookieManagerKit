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

    /** @var list<\Throwable> */
    private array $errors = [];

    private function rewrite(string $html): string
    {
        $this->errors = [];

        return EmbedRewriter::rewrite($html, self::SERVICES, self::TEXT, function (\Throwable $e): void {
            $this->errors[] = $e;
        });
    }

    private function assertNoLiveMatch(string $html): void
    {
        $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);

        foreach ($doc->querySelectorAll('iframe') as $iframe) {
            $src = $iframe->getAttribute('src');

            $this->assertTrue($src === null || EmbedRewriter::match($src, self::SERVICES) === null, 'A matched iframe is live: ' . $src);
            $this->assertStringNotContainsString('youtube', strtolower((string) $src));
        }
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

    public function testAMatchedIframeBecomesAPlaceholderWithAnUnloadedFrame(): void
    {
        $out = $this->rewrite('<p>Hi</p><iframe width="560" height="315" src="https://www.youtube.com/embed/abc" title="A &gt; B" allowfullscreen></iframe><p>Bye</p>');

        $this->assertStringContainsString('<p>Hi</p>', $out);
        $this->assertStringContainsString('<p>Bye</p>', $out);
        $this->assertStringContainsString('data-cmk-embed="marketing"', $out);
        $this->assertStringContainsString('data-cmk-src="https://www.youtube.com/embed/abc"', $out);
        $this->assertStringContainsString('data-cmk-embed-accept="marketing"', $out);
        $this->assertStringContainsString('data-cmk-open', $out);
        $this->assertStringContainsString('Provided by YouTube.', $out);
        $this->assertStringContainsString('Accept Marketing cookies', $out);
        $this->assertStringContainsString('title="A > B"', $out);
        $this->assertStringContainsString('allowfullscreen=""', $out);
        $this->assertStringContainsString('width:560px;', $out);
        $this->assertStringContainsString('min-height:315px;', $out);
        $this->assertDoesNotMatchRegularExpression('/<iframe[^>]*\ssrc=/i', $out);
        $this->assertSame([], $this->errors);
    }

    public function testUnmatchedAndSrcdocAndSourcelessIframesAreLeftAlone(): void
    {
        $html = '<iframe src="https://example.org/x"></iframe><iframe srcdoc="<p>hi</p>" src="https://youtube.com/x"></iframe><iframe></iframe><iframe src="/local"></iframe>';

        $this->assertSame($html, $this->rewrite($html));
    }

    public function testQuotesAndSingleQuotedAndUnquotedAttributesAreHandled(): void
    {
        $out = $this->rewrite("<IFRAME SRC='https://youtube.com/embed/1' class=video data-x=\"1\"></IFRAME>");

        $this->assertStringContainsString('data-cmk-src="https://youtube.com/embed/1"', $out);
        $this->assertStringContainsString('class="video"', $out);
        $this->assertStringContainsString('data-x="1"', $out);
    }

    public function testASourceWithoutAProtocolIsTreatedAsHttps(): void
    {
        $this->assertStringContainsString('data-cmk-src="https://www.youtube.com/e"', $this->rewrite('<iframe src="//www.youtube.com/e"></iframe>'));
    }

    public function testSeveralIframesAreAllRewrittenIndependently(): void
    {
        $out = $this->rewrite('<iframe src="https://youtube.com/a"></iframe>X<iframe src="https://example.org/b"></iframe>Y<iframe src="https://maps.google.com/c"></iframe>');

        $this->assertSame(2, substr_count($out, 'data-cmk-embed="'));
        $this->assertStringContainsString('data-cmk-embed="preferences"', $out);
        $this->assertStringContainsString('<iframe src="https://example.org/b"></iframe>', $out);
    }

    #[DataProvider('maskedRegions')]
    public function testIframesInsideRawTextRegionsAndCommentsAreNotTouched(string $html): void
    {
        $this->assertSame($html, $this->rewrite($html));
    }

    public static function maskedRegions(): iterable
    {
        $frame = '<iframe src="https://youtube.com/x"></iframe>';

        yield 'script' => ['<script>document.write(\'' . $frame . '\')</script>'];
        yield 'style' => ['<style>/* ' . $frame . ' */</style>'];
        yield 'template' => ['<template>' . $frame . '</template>'];
        yield 'title' => ['<title>' . $frame . '</title>'];
        yield 'textarea' => ['<textarea>' . $frame . '</textarea>'];
        yield 'comment' => ['<!-- ' . $frame . ' -->'];
        yield 'unterminated script' => ['<script>var a = \'' . $frame . '\';'];
        yield 'unterminated comment' => ['<!-- ' . $frame];
    }

    public function testAnIframeAfterAMaskedRegionIsStillRewritten(): void
    {
        $out = $this->rewrite('<script>var a = 1;</script><iframe src="https://youtube.com/x"></iframe>');

        $this->assertStringContainsString('<script>var a = 1;</script>', $out);
        $this->assertStringContainsString('data-cmk-embed="marketing"', $out);
    }

    public function testAnIframeInsideNoscriptIsRewrittenBecauseScriptingIsOff(): void
    {
        $this->assertStringContainsString('data-cmk-embed="marketing"', $this->rewrite('<noscript><iframe src="https://youtube.com/x"></iframe></noscript>'));
    }

    public function testAnUnterminatedMatchedIframeIsRewrittenAndNothingStaysLive(): void
    {
        $out = $this->rewrite('<p>A</p><iframe src="https://youtube.com/x"><p>B</p>');

        $this->assertStringContainsString('data-cmk-embed="marketing"', $out);
        $this->assertNoLiveMatch($out);
        $this->assertSame([], $this->errors);
    }

    public function testAnUnterminatedUnmatchedIframeIsLeftAlone(): void
    {
        $html = '<iframe src="https://example.org/x"><p>B</p>';

        $this->assertSame($html, $this->rewrite($html));
        $this->assertSame([], $this->errors);
    }

    public function testHostileTextAndAttributesAreEscaped(): void
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
        $this->assertStringNotContainsString('evil.example', $out);
        $this->assertSame(1, substr_count($out, 'data-cmk-src='));
    }

    public function testNonNumericSizesAreIgnored(): void
    {
        $out = $this->rewrite('<iframe src="https://youtube.com/x" width="100%;position:fixed" height="calc(1px)"></iframe>');

        $this->assertStringNotContainsString('style=', $out);
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

    public function testAGreaterThanInsideAQuotedAttributeDoesNotEndTheTag(): void
    {
        $out = $this->rewrite('<iframe title="a > b" src="https://youtube.com/x"></iframe>');

        $this->assertStringContainsString('data-cmk-src="https://youtube.com/x"', $out);
        $this->assertDoesNotMatchRegularExpression('/<iframe[^>]*\ssrc=/i', $out);
    }

    public function testAMatchedIframeWithAnUnclosedQuoteIsNeverLive(): void
    {
        $out = $this->rewrite('<p>A</p><iframe src="https://youtube.com/x" title="oops></iframe><p>B</p>');

        $this->assertNoLiveMatch($out);
        $this->assertStringContainsString('<p>A</p>', $out);
    }

    public function testAnEntityEncodedSourceIsDecodedBeforeMatching(): void
    {
        $out = $this->rewrite('<iframe src="https://&#121;outube.com/x"></iframe>');

        $this->assertStringContainsString('data-cmk-embed="marketing"', $out);
        $this->assertDoesNotMatchRegularExpression('/<iframe[^>]*\ssrc=/i', $out);
    }

    public function testAScriptOpenerInsideAnAttributeDoesNotHideALaterIframe(): void
    {
        $out = $this->rewrite('<div title="<script>"><iframe src="https://youtube.com/x"></iframe></div>');

        $this->assertStringContainsString('data-cmk-embed="marketing"', $out);
        $this->assertDoesNotMatchRegularExpression('/<iframe[^>]*\ssrc=/i', $out);
    }

    public function testACommentOpenerInsideAnAttributeDoesNotHideALaterIframe(): void
    {
        $out = $this->rewrite('<a title="<!--"></a><iframe src="https://youtube.com/x"></iframe>');

        $this->assertStringContainsString('data-cmk-embed="marketing"', $out);
    }

    public function testAForgedMaskTokenInThePageCannotInjectContent(): void
    {
        $html = '<p><!--cmk00000000000000000--></p><iframe src="https://youtube.com/x"></iframe>';
        $out = $this->rewrite($html);

        $this->assertStringContainsString('<p><!--cmk00000000000000000--></p>', $out);
        $this->assertStringContainsString('data-cmk-embed="marketing"', $out);
    }

    public function testAnIframeNestedInsideAnotherIsHandledWithoutLeavingALiveFrame(): void
    {
        // An iframe's content is raw text to a browser, so the inner tag is text inside an unmatched outer frame.
        $html = '<iframe src="https://example.org/"><iframe src="https://youtube.com/x"></iframe></iframe>';

        $this->assertSame($html, $this->rewrite($html));
    }

    public function testAVeryLargePageWithManyIframesFinishesQuicklyAndLeavesNoneLive(): void
    {
        $html = str_repeat('<p>filler text</p><iframe src="https://youtube.com/embed/x" title="t"></iframe>', 5000)
            . '<iframe src="https://youtube.com/embed/y" title="' . str_repeat('a', 1_000_000);
        $start = microtime(true);
        $out = $this->rewrite($html);

        $this->assertLessThan(3.0, microtime(true) - $start);
        $this->assertSame(0, preg_match('/<iframe[^>]*\ssrc=/i', $out));
        $this->assertSame(5000, substr_count($out, 'data-cmk-embed="marketing"'));
    }

    public function testManyUnterminatedIframesFinishQuicklyAndLeaveNoneLive(): void
    {
        $html = str_repeat('<iframe src="https://youtube.com/embed/x"><p>filler</p>', 3000);
        $start = microtime(true);
        $out = $this->rewrite($html);

        $this->assertLessThan(3.0, microtime(true) - $start);
        $this->assertNoLiveMatch($out);
    }

    public function testFailClosedRemovesEveryIframeTag(): void
    {
        $out = EmbedRewriter::failClosed('<p>x</p><IFRAME src="a" title=">"></iframe ><iframe-x></iframe-x><iframe/src=b><iframe src="unterminated');

        $this->assertDoesNotMatchRegularExpression('/<\/?iframe[\s\/>]/i', $out);
        $this->assertStringContainsString('<p>x</p>', $out);
        $this->assertStringContainsString('<iframe-x>', $out);
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
        yield 'C2 quote in unquoted' => ['<iframe title=a"src=x" src="https://youtube.com/x"></iframe>'];
        yield 'C2 lt in unquoted' => ['<iframe title=a<src=x src="https://youtube.com/x"></iframe>'];
        yield 'C2 equals in unquoted' => ['<iframe title=a=src=x src="https://youtube.com/x"></iframe>'];
        yield 'C3 entity tab' => ['<iframe src="https://you&Tab;tube.com/x"></iframe>'];
        yield 'C3 literal newline' => ["<iframe src=\"https://you\ntube.com/x\"></iframe>"];
        yield 'C3 entity without semicolon' => ['<iframe src="https://&#121outube.com/x"></iframe>'];
        yield 'C3 backslashes' => ['<iframe src="https:\\\\youtube.com\\x"></iframe>'];
        yield 'C3 slash backslash' => ['<iframe src="/\\youtube.com/x"></iframe>'];
        yield 'C3 triple slash' => ['<iframe src="https:///youtube.com/x"></iframe>'];
        yield 'C3 percent host' => ['<iframe src="https://%79outube.com/x"></iframe>'];
        yield 'C3 fullwidth' => ['<iframe src="https://ｙoutube.com/x"></iframe>'];
        yield 'C3 leading controls' => ["<iframe src=\" \x01https://youtube.com/x\"></iframe>"];
        yield 'C3 port and userinfo' => ['<iframe src="https://user:pw@youtube.com:443/x"></iframe>'];
        yield 'C3 trailing dots' => ['<iframe src="https://youtube.com../x"></iframe>'];
        yield 'I2 close tag in comment' => ['<iframe src="https://youtube.com/x"><!-- </iframe> --></iframe>hello'];
    }

    #[DataProvider('attacks')]
    public function testAttackInputsNeverLeaveAMatchedIframeLive(string $html): void
    {
        $out = $this->rewrite($html);

        $this->assertStringContainsString('data-cmk-embed="marketing"', $out);
        $this->assertNoLiveMatch($out);
    }

    public function testALiteralIframeInsideAnAttributeIsNotRewrittenAndRealContentSurvives(): void
    {
        $html = '<div title="<iframe src=https://youtube.com/x>">hello</div><iframe src="https://example.org/"></iframe>';
        $out = $this->rewrite($html);

        $this->assertSame($html, $out);
    }

    public function testIFramesAroundAnAttributeOnlyLookalikeKeepTheirNeighbours(): void
    {
        $out = $this->rewrite('<div title="<iframe src=https://youtube.com/x>">hello</div><iframe src="https://youtube.com/y"></iframe><p>after</p>');

        $this->assertStringContainsString('hello', $out);
        $this->assertStringContainsString('<p>after</p>', $out);
        $this->assertSame(1, substr_count($out, 'data-cmk-embed="'));
        $this->assertNoLiveMatch($out);
    }

    public function testLookalikeHostsAreNotRewrittenEvenWhenSmuggled(): void
    {
        $html = '<iframe src="https://evilyoutube.com/x"></iframe><iframe src="https://youtube.com.evil.com/x"></iframe><iframe src="https://youtube.com@evil.com/x"></iframe><iframe src="https://evil.com/?u=youtube.com"></iframe>';

        $this->assertSame($html, $this->rewrite($html));
    }

    public function testAnUnparseableHostThatNamesAServiceIsBlocked(): void
    {
        $out = $this->rewrite('<iframe src="https://[youtube.com]/x"></iframe>');

        $this->assertStringContainsString('data-cmk-embed="marketing"', $out);
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

    public function testHostIsReadLikeABrowserReadsIt(): void
    {
        $this->assertSame('youtube.com', EmbedRewriter::host("  https://You\tTube.com:8080/x"));
        $this->assertNull(EmbedRewriter::host('https://exa mple.com/'));
    }
}
