<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Events;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Events\ConsentManager;

final class ConsentManagerEmbedTest extends TestCase
{
    private function snapshot(): array
    {
        return [
            'defaults' => ['embedMessage' => 'M {service}', 'embedAcceptLabel' => 'A {category}', 'customise' => 'C'],
            'banner' => [],
            'categories' => [['handle' => 'marketing', 'necessary' => false, 'content' => [], 'services' => [['name' => 'YouTube', 'domains' => ['youtube.com']]]]],
        ];
    }

    public function testAPageWithoutIframesIsReturnedWithoutTouchingTheSnapshot(): void
    {
        $this->assertSame('<p>x</p>', ConsentManager::rewriteEmbeds('<p>x</p>', fn() => throw new \LogicException('snapshot read'), 'en_GB', 'en_GB', static function (\Throwable $e): void {}));
    }

    public function testAMatchedIframeIsReplaced(): void
    {
        $out = ConsentManager::rewriteEmbeds('<iframe src="https://youtube.com/x"></iframe>', fn() => $this->snapshot(), 'en_GB', 'en_GB', static function (\Throwable $e): void {});

        $this->assertStringContainsString('data-cmk-embed=marketing', $out);
    }

    public function testAFailureLeavesThePageAsItWasAndIsReported(): void
    {
        $errors = [];
        $html = '<iframe src="https://youtube.com/x"></iframe>';

        $out = ConsentManager::rewriteEmbeds($html, fn() => throw new \RuntimeException('db down'), 'en_GB', 'en_GB', function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });

        $this->assertSame($html, $out);
        $this->assertCount(1, $errors);
    }

    /**
     * The embeds step lives inside onPageOutput, behind the same $active gate as the tracking snippets (set only by
     * beforeRender for public, non-edit pages). With the gate closed the event is never even read, so the page
     * (edit mode, dashboard, excluded paths) is never rewritten.
     */
    public function testOnPageOutputDoesNothingWhenTheGateIsClosed(): void
    {
        ConsentManager::reset();

        $event = new class {
            public int $reads = 0;

            public function getArgument(string $name): string
            {
                ++$this->reads;

                return '<iframe src="https://youtube.com/x"></iframe>';
            }

            public function setArgument(string $name, string $value): void
            {
                throw new \LogicException('page must not be rewritten');
            }
        };

        ConsentManager::onPageOutput($event);

        $this->assertSame(0, $event->reads);
        $this->assertFalse(ConsentManager::isActive());
    }
}
