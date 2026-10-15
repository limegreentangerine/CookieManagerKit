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

    public function testGuardedRunsTheCallable(): void
    {
        $ran = false;

        ConsentManager::guarded(static function () use (&$ran): void {
            $ran = true;
        });

        $this->assertTrue($ran);
    }

    public function testGuardedSwallowsAThrowableAndReportsItToTheHandler(): void
    {
        $caught = null;
        $error = new \RuntimeException('database is down');

        ConsentManager::guarded(static function () use ($error): void {
            throw $error;
        }, static function (\Throwable $e) use (&$caught): void {
            $caught = $e;
        });

        $this->assertSame($error, $caught);
    }

    public function testGuardedSwallowsErrorsAndSurvivesAFailingHandler(): void
    {
        ConsentManager::guarded(static function (): void {
            throw new \TypeError('bad');
        }, static function (): void {
            throw new \RuntimeException('handler failed too');
        });

        $this->addToAssertionCount(1);
    }

    public function testGuardedDefaultHandlerDoesNotPropagate(): void
    {
        // The default handler is ErrorLog::record, which swallows its own failures (no container in unit tests).
        ConsentManager::guarded(static function (): void {
            throw new \RuntimeException('boom');
        });

        $this->addToAssertionCount(1);
    }
}
