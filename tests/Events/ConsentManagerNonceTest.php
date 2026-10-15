<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Events;

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
}
