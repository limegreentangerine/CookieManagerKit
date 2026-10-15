<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Log;

use ClassKit\Log\Logger;
use PHPUnit\Framework\TestCase;
use CookieManagerKit\Log\CookieManagerLogger;

final class CookieManagerLoggerTest extends TestCase
{
    public function testExtendsClassKitLoggerWithNoArgumentConstructor(): void
    {
        $this->assertTrue(class_exists(CookieManagerLogger::class));

        $class = new \ReflectionClass(CookieManagerLogger::class);

        $this->assertTrue($class->isSubclassOf(Logger::class));

        $constructor = $class->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPublic());
        $this->assertSame(0, $constructor->getNumberOfParameters());
    }
}
