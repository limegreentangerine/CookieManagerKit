<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use CookieManagerKit\Scan\Host;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class HostTest extends TestCase
{
    /**
     * @return array<string, array{mixed, ?string}>
     */
    public static function hosts(): array
    {
        return [
            'plain' => ['example.com', 'example.com'],
            'upper case' => ['WWW.Example.COM', 'www.example.com'],
            'trailing dot' => ['example.com.', 'example.com'],
            'punycode' => ['xn--bcher-kva.example', 'xn--bcher-kva.example'],
            'ipv4' => ['203.0.113.7', '203.0.113.7'],
            'single label' => ['localhost', null],
            'space' => ['exa mple.com', null],
            'underscore' => ['bad_host.example.com', null],
            'leading hyphen' => ['-bad.example.com', null],
            'angle brackets' => ['<script>.example.com', null],
            'numeric tld' => ['1.2.3.999', null],
            'too long' => [str_repeat('a', 64) . '.example.com', null],
            'whole name too long' => [str_repeat('a.', 130) . 'com', null],
            'empty' => ['', null],
            'array' => [['example.com'], null],
            'null' => [null, null],
            'ipv6' => ['[::1]', null],
            'double trailing dot' => ['example.com..', null],
            'trailing nul' => ["example.com\0", null],
            'trailing newline' => ["a.b\n", null],
            'hex labels' => ['0x7f.0x1', null],
            'hex label inside' => ['0x7f.example.com', null],
        ];
    }

    #[DataProvider('hosts')]
    public function testNormalise(mixed $given, ?string $expected): void
    {
        $this->assertSame($expected, Host::normalise($given));
    }

    /**
     * @return array<string, array{mixed, ?string}>
     */
    public static function urls(): array
    {
        return [
            'https' => ['https://cdn.example.net/a.js?x=1', 'cdn.example.net'],
            'http' => ['http://CDN.Example.net/a.js', 'cdn.example.net'],
            'scheme relative' => ['//stats.example.net/p.gif', 'stats.example.net'],
            'with port' => ['https://cdn.example.net:8443/a.js', 'cdn.example.net'],
            'relative path' => ['/local.js', null],
            'relative file' => ['local.js', null],
            'javascript' => ['javascript:alert(1)', null],
            'data' => ['data:image/png;base64,AAAA', null],
            'ftp' => ['ftp://files.example.net/x', null],
            'userinfo' => ['https://example.com@evil.example.net/x', null],
            'backslash trick' => ['https://evil.com\\@good.com/', null],
            'backslash path' => ['https://good.com\\x', null],
            'inner control char' => ["https://good.com/\x01", null],
            'inner space' => ['https://good.com/a b', null],
            'garbage' => ['https://', null],
            'spaces in host' => ['https://exa mple.com/x', null],
            'whitespace around' => ["  https://cdn.example.net/a.js \n", 'cdn.example.net'],
            'not a string' => [42, null],
        ];
    }

    #[DataProvider('urls')]
    public function testFromUrl(mixed $given, ?string $expected): void
    {
        $this->assertSame($expected, Host::fromUrl($given));
    }

    public function testSameSiteIsExactOrTheWwwTwin(): void
    {
        $this->assertTrue(Host::sameSite('example.com', 'example.com'));
        $this->assertTrue(Host::sameSite('www.example.com', 'example.com'));
        $this->assertTrue(Host::sameSite('example.com', 'www.example.com'));
        $this->assertFalse(Host::sameSite('cdn.example.com', 'example.com'));
        $this->assertFalse(Host::sameSite('example.com.evil.net', 'example.com'));
    }
}
