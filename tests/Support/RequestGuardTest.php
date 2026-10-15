<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\RequestGuard;

final class RequestGuardTest extends TestCase
{
    public function testSameOriginIsAccepted(): void
    {
        $this->assertTrue(RequestGuard::isSameOrigin('https://example.com', null, 'example.com'));
        $this->assertTrue(RequestGuard::isSameOrigin('http://localhost:8080', null, 'localhost:8080'));
        $this->assertTrue(RequestGuard::isSameOrigin('https://EXAMPLE.com', null, 'example.com'));
    }

    public function testRefererIsUsedWhenThereIsNoOrigin(): void
    {
        $this->assertTrue(RequestGuard::isSameOrigin(null, 'https://example.com/some/page?x=1', 'example.com'));
        $this->assertFalse(RequestGuard::isSameOrigin(null, 'https://evil.example/page', 'example.com'));
    }

    public function testOriginWinsOverReferer(): void
    {
        $this->assertFalse(RequestGuard::isSameOrigin('https://evil.example', 'https://example.com/', 'example.com'));
    }

    public function testRejectsForeignMissingAndMalformedOrigins(): void
    {
        foreach (['https://evil.example', 'https://example.com.evil.example', 'null', 'garbage', '', 'https://example.com:444'] as $origin) {
            $this->assertFalse(RequestGuard::isSameOrigin($origin, null, 'example.com'), $origin);
        }

        $this->assertFalse(RequestGuard::isSameOrigin(null, null, 'example.com'));
    }
}
