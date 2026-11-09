<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\RateLimit;

final class RateLimitTest extends TestCase
{
    public function testAllowsUpToTheLimitThenBlocksWithinTheWindow(): void
    {
        $state = null;

        for ($i = 0; $i < 3; $i++) {
            [$allowed, $state] = RateLimit::step($state, 1000, 3, 60);
            $this->assertTrue($allowed);
        }

        [$allowed, $state] = RateLimit::step($state, 1010, 3, 60);
        $this->assertFalse($allowed);
        $this->assertSame(3, $state['used']);
    }

    public function testStartsANewWindowAfterItExpires(): void
    {
        $state = ['start' => 1000, 'used' => 3];

        [$allowed, $state] = RateLimit::step($state, 1060, 3, 60);

        $this->assertTrue($allowed);
        $this->assertSame(['start' => 1060, 'used' => 1], $state);
    }

    public function testASteadyTrickleDoesNotExtendTheWindow(): void
    {
        $state = null;
        [, $state] = RateLimit::step($state, 1000, 100, 60);

        foreach ([1020, 1040, 1059] as $now) {
            [, $state] = RateLimit::step($state, $now, 100, 60);
        }

        $this->assertSame(1000, $state['start']);
    }

    public function testGarbageStateIsReplaced(): void
    {
        [$allowed, $state] = RateLimit::step(['start' => 'x', 'used' => 'y'], 500, 3, 60);

        $this->assertTrue($allowed);
        $this->assertSame(['start' => 500, 'used' => 1], $state);
    }
}
