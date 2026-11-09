<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\Output;
use PHPUnit\Framework\Attributes\DataProvider;

final class OutputTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function payloadProvider(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>'],
            'attribute breakout' => ['"><script>alert(1)</script>'],
            'event handler' => ['<img src=x onerror=alert(1)>'],
            'single quote breakout' => ["' onmouseover='alert(1)"],
            'svg onload' => ['<svg onload=alert(1)>'],
        ];
    }

    #[DataProvider('payloadProvider')]
    public function testEscapesEveryPayload(string $payload): void
    {
        $escaped = Output::e($payload);

        $this->assertStringNotContainsString('<', $escaped);
        $this->assertStringNotContainsString('"', $escaped);
        $this->assertStringNotContainsString("'", $escaped);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function urlProvider(): array
    {
        return [
            'https' => ['https://example.com/a?b=1&c=2', 'https://example.com/a?b=1&c=2'],
            'http' => ['http://example.com', 'http://example.com'],
            'relative path' => ['/privacy', '/privacy'],
            'relative no slash' => ['privacy', 'privacy'],
            'javascript' => ['javascript:alert(1)', ''],
            'mixed case javascript' => ['JaVaScRiPt:alert(1)', ''],
            'tab inside scheme' => ["java\tscript:alert(1)", ''],
            'newline inside scheme' => ["java\nscript:alert(1)", ''],
            'data' => ['data:text/html,<script>alert(1)</script>', ''],
            'vbscript' => ['vbscript:msgbox(1)', ''],
            'mailto not allowed' => ['mailto:a@example.com', ''],
            'protocol relative' => ['//evil.example/x', ''],
            'backslash protocol relative' => ['/\\evil.example/x', ''],
            'double backslash' => ['\\\\evil.example', ''],
            'empty' => ['', ''],
            'whitespace' => ['   ', ''],
        ];
    }

    #[DataProvider('urlProvider')]
    public function testSafeUrlAllowsOnlyHttpAndRelative(string $input, string $expected): void
    {
        $this->assertSame($expected, Output::safeUrl($input));
    }

    public function testUrlIsEncodedOrHash(): void
    {
        $this->assertSame('https://example.com/a?b=1&amp;c=2', Output::url('https://example.com/a?b=1&c=2'));
        $this->assertSame('#', Output::url('javascript:alert(1)'));
        $this->assertSame('#', Output::url(null));
    }

    public function testJsonCannotCloseAScriptOrAttribute(): void
    {
        $json = Output::json(['a' => '</script><b onclick="x">&\'']);

        foreach (['<', '>', '&', "'"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
        $this->assertSame(['a' => '</script><b onclick="x">&\''], json_decode($json, true));
    }

    public function testJsonThrowsOnInvalidUtf8(): void
    {
        $this->expectException(\JsonException::class);
        Output::json(["bad\xB1" => 'x']);
    }
}
