<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\StaleCheck;

final class StaleCheckTest extends TestCase
{
    public function testNeverRunIsStale(): void
    {
        $this->assertTrue(StaleCheck::isStale(null, 1000000, 60));
    }

    public function testRecentIsNotStaleAndOldIs(): void
    {
        $now = 100 * 86400;

        $this->assertFalse(StaleCheck::isStale($now - 59 * 86400, $now, 60));
        $this->assertFalse(StaleCheck::isStale($now - 60 * 86400, $now, 60));
        $this->assertTrue(StaleCheck::isStale($now - 60 * 86400 - 1, $now, 60));
    }

    public function testATimeInTheFutureIsNotStale(): void
    {
        $this->assertFalse(StaleCheck::isStale(2000000, 1000000, 60));
    }
}
