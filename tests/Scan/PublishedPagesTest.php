<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\PublishedPages;

final class PublishedPagesTest extends TestCase
{
    public function testTheHostAlwaysComesFromTheConfiguredBaseNeverFromTheResolvedUrl(): void
    {
        $this->assertSame('https://example.com/about', PublishedPages::rebase('https://attacker.example.net/about', 'https://example.com'));
        $this->assertSame('https://example.com/about?x=1', PublishedPages::rebase('http://other.example.org:8080/about?x=1', 'https://example.com'));
    }

    public function testThePathPrefixOfASubdirectoryInstallIsKept(): void
    {
        $this->assertSame('https://example.com/site/about', PublishedPages::rebase('https://example.com/site/about', 'https://example.com'));
    }

    public function testRelativeResolvedUrlsGetTheBase(): void
    {
        $this->assertSame('https://example.com/about', PublishedPages::rebase('/about', 'https://example.com'));
        $this->assertSame('https://example.com/', PublishedPages::rebase('', 'https://example.com'));
    }

    public function testAnUnusableResolvedUrlGivesNull(): void
    {
        $this->assertNull(PublishedPages::rebase('http://', 'https://example.com'));
    }

    public function testAProtocolRelativePathCannotChangeTheHost(): void
    {
        $this->assertSame('https://example.com/x', PublishedPages::rebase('//evil.example.net/x', 'https://example.com'));
    }

    public function testTheBasePortIsKept(): void
    {
        $this->assertSame('http://example.com:8080/a', PublishedPages::rebase('https://elsewhere.example.net/a', 'http://example.com:8080'));
    }
}
