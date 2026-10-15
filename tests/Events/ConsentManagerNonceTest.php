<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Events;

use Concrete\Core\Page\Page;
use PHPUnit\Framework\TestCase;
use CookieManagerKit\Events\ConsentManager;

final class ConsentManagerNonceTest extends TestCase
{
    public function testStringIsItsOwnNonce(): void
    {
        $this->assertSame('abcd1234efgh', ConsentManager::nonceFrom('abcd1234efgh'));
    }

    public function testClosureResultIsUsedWhenString(): void
    {
        $this->assertSame('abcd1234efgh', ConsentManager::nonceFrom(static fn() => 'abcd1234efgh'));
    }

    public function testClosureReturningNonStringOrThrowingGivesNull(): void
    {
        $this->assertNull(ConsentManager::nonceFrom(static fn() => 42));
        $this->assertNull(ConsentManager::nonceFrom(static fn() => null));
        $this->assertNull(ConsentManager::nonceFrom(static function () {
            throw new \RuntimeException('boom');
        }));
    }

    public function testFunctionNameStringIsNeverCalled(): void
    {
        $this->assertSame('password_hash', ConsentManager::nonceFrom('password_hash'));
        $this->assertSame('phpinfo', ConsentManager::nonceFrom('phpinfo'));
    }

    public function testOtherTypesGiveNull(): void
    {
        $this->assertNull(ConsentManager::nonceFrom(null));
        $this->assertNull(ConsentManager::nonceFrom(['a', 'b']));
        $this->assertNull(ConsentManager::nonceFrom(new \stdClass()));
        $this->assertNull(ConsentManager::nonceFrom(new class {
            public function __invoke(): string
            {
                return 'abcd1234efgh';
            }
        }));
    }

    public function testGuardedRunsTheCallable(): void
    {
        $ran = false;

        ConsentManager::guarded(static function () use (&$ran): void {
            $ran = true;
        });

        $this->assertTrue($ran);
    }

    public function testGuardedSwallowsAThrowableAndReportsItToTheHandler(): void
    {
        $caught = null;
        $error = new \RuntimeException('database is down');

        ConsentManager::guarded(static function () use ($error): void {
            throw $error;
        }, static function (\Throwable $e) use (&$caught): void {
            $caught = $e;
        });

        $this->assertSame($error, $caught);
    }

    public function testGuardedSwallowsErrorsAndSurvivesAFailingHandler(): void
    {
        ConsentManager::guarded(static function (): void {
            throw new \TypeError('bad');
        }, static function (): void {
            throw new \RuntimeException('handler failed too');
        });

        $this->addToAssertionCount(1);
    }

    public function testGuardedDefaultHandlerDoesNotPropagate(): void
    {
        // The default handler is ErrorLog::record, which swallows its own failures (no container in unit tests).
        ConsentManager::guarded(static function (): void {
            throw new \RuntimeException('boom');
        });

        $this->addToAssertionCount(1);
    }

    public function testPageOutputGateIsClosedUntilARenderActivatedIt(): void
    {
        ConsentManager::reset();
        $this->assertFalse(ConsentManager::isActive());
    }

    public function testRewriteSnippetsMakesBothSnippetsInert(): void
    {
        $contents = '<head><script>a()</script></head><body><script>b()</script></body>';
        $out = ConsentManager::rewriteSnippets($contents, ['<script>a()</script>', '<script>b()</script>'], null, static function (): void {
            throw new \LogicException('no error expected');
        });

        $this->assertSame(2, substr_count($out, 'type="text/plain" data-cmk-category="analytics"'));
    }

    public function testRewriteSnippetsRemovesASnippetTheRewriterRefuses(): void
    {
        $out = ConsentManager::rewriteSnippets('<p>x</p><script>a()</script>', ['<script>a()</script>'], null, static function (): void {}, static fn() => '');

        $this->assertSame('<p>x</p>', $out);
    }

    public function testRewriteSnippetsRemovesASnippetWhoseRewriteThrowsAndReportsIt(): void
    {
        $seen = null;
        $out = ConsentManager::rewriteSnippets(
            '<p>x</p><script>a()</script><script>a()</script>',
            ['<script>a()</script>'],
            null,
            static function (\Throwable $e) use (&$seen): void {
                $seen = $e;
            },
            static function (): string {
                throw new \RuntimeException('parser blew up');
            },
        );

        $this->assertSame('<p>x</p>', $out);
        $this->assertInstanceOf(\RuntimeException::class, $seen);
    }

    public function testRewriteSnippetsIgnoresMissingAndEmptySnippets(): void
    {
        $contents = '<p>nothing here</p>';

        $this->assertSame($contents, ConsentManager::rewriteSnippets($contents, ['', '<script>zzz()</script>'], null, static function (): void {}));
    }

    public function testRewriteSnippetsInertsBothWhenTheFooterContainsTheHeaderSnippet(): void
    {
        $header = '<script>a()</script>';
        $footer = '<script>a()</script><script>b()</script>';
        $contents = '<head>' . $header . '</head><body>x' . $footer . '</body>';

        $out = ConsentManager::rewriteSnippets($contents, [$header, $footer], null, static function (): void {
            throw new \LogicException('no error expected');
        });

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertSame(3, substr_count($out, 'type="text/plain" data-cmk-category="analytics"'));
        $this->assertStringContainsString('b()', $out);
    }

    public function testRewriteSnippetsHandlesIdenticalHeaderAndFooter(): void
    {
        $snippet = '<script>a()</script>';
        $out = ConsentManager::rewriteSnippets('<head>' . $snippet . '</head><body>' . $snippet . '</body>', [$snippet, $snippet], null, static function (): void {});

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertSame(2, substr_count($out, 'data-cmk-category="analytics"'));
    }

    public function testRewriteSnippetsRemovesOnlyTheThrowingSnippetAndReportsEachThrowOnce(): void
    {
        $errors = [];
        $out = ConsentManager::rewriteSnippets(
            '<p>1</p><script>bad()</script><p>2</p><script>good()</script><p>3</p>',
            ['<script>bad()</script>', '<script>good()</script>'],
            null,
            static function (\Throwable $e) use (&$errors): void {
                $errors[] = $e->getMessage();
            },
            static function (string $code): string {
                if (str_contains($code, 'bad')) {
                    throw new \RuntimeException('bad snippet');
                }

                return '[inert]';
            },
        );

        $this->assertSame('<p>1</p><p>2</p>[inert]<p>3</p>', $out);
        $this->assertSame(['bad snippet'], $errors);
    }

    public function testRewriteSnippetsLeavesContentsUntouchedWhenNothingMatches(): void
    {
        $contents = '<p>nothing</p>';
        $called = false;

        $out = ConsentManager::rewriteSnippets($contents, ['<script>a()</script>', ''], null, static function (): void {}, static function () use (&$called): string {
            $called = true;

            return 'x';
        });

        $this->assertSame($contents, $out);
        $this->assertFalse($called);
    }

    public function testRewriteSnippetsNeverRewritesAReplacementAgain(): void
    {
        $out = ConsentManager::rewriteSnippets('<i>A</i>', ['A', 'B'], null, static function (): void {}, static fn(string $code): string => $code === 'A' ? 'B' : 'C');

        $this->assertSame('<i>B</i>', $out);
    }

    public function testBeforeRenderClosesTheGateForAnExcludedPageAndOpensItForAPublicOne(): void
    {
        $excluded = $this->createStub(Page::class);
        $excluded->method('isError')->willReturn(true);

        $public = $this->createStub(Page::class);
        $public->method('isError')->willReturn(false);
        $public->method('isAdminArea')->willReturn(false);
        $public->method('isEditMode')->willReturn(false);
        $public->method('isMasterCollection')->willReturn(false);
        $public->method('getCollectionPath')->willReturn('/about');
        $public->method('getPageController')->willThrowException(new \RuntimeException('no container in unit tests'));

        try {
            // A public page opens the gate (the risky work after it throws here, as there is no container).
            ConsentManager::beforeRender($public);
        } catch (\Throwable) {
        }

        $this->assertTrue(ConsentManager::isActive(), 'opened right after the exclusion checks, before any risky work');

        ConsentManager::beforeRender($excluded);
        $this->assertFalse(ConsentManager::isActive());

        ConsentManager::beforeRender(null);
        $this->assertFalse(ConsentManager::isActive());
    }
    public static function setUpBeforeClass(): void
    {
        // Concrete's class files refuse to load without it.
        defined('C5_EXECUTE') || define('C5_EXECUTE', true);
    }
}
