<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\ConsentRequest;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConsentRequestTest extends TestCase
{
    private const KNOWN = ['necessary', 'preferences', 'analytics', 'marketing'];

    public function testAcceptsAValidBodyAndAlwaysIncludesNecessary(): void
    {
        $parsed = ConsentRequest::parse('application/json', '{"v":2,"c":["analytics"]}', self::KNOWN);

        $this->assertSame(['v' => 2, 'c' => ['necessary', 'analytics']], $parsed);
    }

    public function testDeduplicatesAndKeepsNecessaryOnce(): void
    {
        $parsed = ConsentRequest::parse('application/json', '{"v":1,"c":["necessary","analytics","analytics"]}', self::KNOWN);

        $this->assertSame(['necessary', 'analytics'], $parsed['c']);
    }

    public function testContentTypeMayCarryACharset(): void
    {
        $this->assertNotNull(ConsentRequest::parse('application/json; charset=utf-8', '{"v":1,"c":[]}', self::KNOWN));
        $this->assertNotNull(ConsentRequest::parse('Application/JSON', '{"v":1,"c":[]}', self::KNOWN));
    }

    public function testAcceptsABodyOfExactlyTheMaximumSize(): void
    {
        $body = '{"v":1,"c":[]}' . str_repeat(' ', 2048 - 14);

        $this->assertSame(2048, strlen($body));
        $this->assertNotNull(ConsentRequest::parse('application/json', $body, self::KNOWN));
    }

    /**
     * @return array<string, array{?string, string}>
     */
    public static function rejectedProvider(): array
    {
        return [
            'no content type' => [null, '{"v":1,"c":[]}'],
            'form content type' => ['application/x-www-form-urlencoded', '{"v":1,"c":[]}'],
            'text plain (simple CORS request)' => ['text/plain', '{"v":1,"c":[]}'],
            'json lookalike' => ['application/jsonp', '{"v":1,"c":[]}'],
            'empty body' => ['application/json', ''],
            'not json' => ['application/json', 'v=1&c=analytics'],
            'json scalar' => ['application/json', '5'],
            'json list' => ['application/json', '[1,["analytics"]]'],
            'oversize' => ['application/json', '{"v":1,"c":[]}' . str_repeat(' ', 3000)],
            'one byte over the cap' => ['application/json', '{"v":1,"c":[]}' . str_repeat(' ', 2049 - 14)],
            'unknown key' => ['application/json', '{"v":1,"c":[],"extra":true}'],
            'missing v' => ['application/json', '{"c":[]}'],
            'missing c' => ['application/json', '{"v":1}'],
            'v as string' => ['application/json', '{"v":"1","c":[]}'],
            'v zero' => ['application/json', '{"v":0,"c":[]}'],
            'v negative' => ['application/json', '{"v":-1,"c":[]}'],
            'v float' => ['application/json', '{"v":1.5,"c":[]}'],
            'v huge' => ['application/json', '{"v":99999999,"c":[]}'],
            'c as object' => ['application/json', '{"v":1,"c":{"a":"analytics"}}'],
            'c as string' => ['application/json', '{"v":1,"c":"analytics"}'],
            'unknown handle' => ['application/json', '{"v":1,"c":["analytics","bogus"]}'],
            'script handle' => ['application/json', '{"v":1,"c":["<script>alert(1)</script>"]}'],
            'handle with newline' => ['application/json', '{"v":1,"c":["analytics\n"]}'],
            'non-string handle' => ['application/json', '{"v":1,"c":[1]}'],
            'nested handle' => ['application/json', '{"v":1,"c":[["analytics"]]}'],
            'too many' => ['application/json', '{"v":1,"c":[' . implode(',', array_fill(0, 21, '"analytics"')) . ']}'],
            'too deep' => ['application/json', '{"v":1,"c":[[[[[]]]]]}'],
        ];
    }

    #[DataProvider('rejectedProvider')]
    public function testRejectsEverythingUnexpected(?string $contentType, string $body): void
    {
        $this->assertNull(ConsentRequest::parse($contentType, $body, self::KNOWN));
    }
}
