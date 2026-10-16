<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Pattern;
use PHPUnit\Framework\Attributes\DataProvider;

final class PatternTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool, bool}>
     */
    public static function cases(): array
    {
        return [
            'exact' => ['_ga', '_ga', false, true],
            'exact is case sensitive' => ['_ga', '_GA', false, false],
            'exact insensitive' => ['_ga', '_GA', true, true],
            'prefix wildcard' => ['_ga_*', '_ga_ABC123', false, true],
            'prefix wildcard needs the prefix' => ['_ga_*', '_gb_ABC', false, false],
            'suffix wildcard' => ['*_session', 'x_session', false, true],
            'middle wildcard' => ['_pk_id.*.x', '_pk_id.1.x', false, true],
            'wildcard matches nothing' => ['_gat*', '_gat', false, true],
            'regex characters are literal' => ['a.b', 'axb', false, false],
            'regex characters in wildcards are literal' => ['a.*', 'a.b', false, true],
            'empty pattern never matches' => ['', '', false, false],
            'too many wildcards never match' => ['*a*b*c*d', 'xaxbxcxd', false, false],
            'overlong pattern never matches' => [str_repeat('a', 129), str_repeat('a', 129), false, false],
            'overlong subject never matches' => ['a*', 'a' . str_repeat('b', 300), false, false],
            'newline is not matched by the dot' => ['a*', "a\nb", false, true],
        ];
    }

    #[DataProvider('cases')]
    public function testMatches(string $pattern, string $subject, bool $insensitive, bool $expected): void
    {
        $this->assertSame($expected, Pattern::matches($pattern, $subject, $insensitive));
    }

    public function testPathologicalWildcardsStayFast(): void
    {
        $start = microtime(true);

        $this->assertFalse(Pattern::matches('*a*a*a*b', str_repeat('a', 250)));
        $this->assertLessThan(0.5, microtime(true) - $start);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function hosts(): array
    {
        return [
            'exact' => ['www.google-analytics.com', 'www.google-analytics.com', true],
            'exact ignores case' => ['WWW.Google-Analytics.com', 'www.google-analytics.com', true],
            'wildcard matches a subdomain' => ['*.doubleclick.net', 'googleads.g.doubleclick.net', true],
            'wildcard matches the apex' => ['*.doubleclick.net', 'doubleclick.net', true],
            'wildcard does not match a lookalike' => ['*.doubleclick.net', 'baddoubleclick.net', false],
            'wildcard does not match a suffix trick' => ['*.doubleclick.net', 'doubleclick.net.evil.example', false],
            'inner wildcard' => ['ads*.example.com', 'ads2.example.com', true],
        ];
    }

    #[DataProvider('hosts')]
    public function testMatchesHost(string $pattern, string $host, bool $expected): void
    {
        $this->assertSame($expected, Pattern::matchesHost($pattern, $host));
    }
}
