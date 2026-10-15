<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\ErrorLog;

final class ErrorLogTest extends TestCase
{
    public function testControlCharactersBecomeSpaces(): void
    {
        $line = ErrorLog::format(new \RuntimeException("a\nb\rc\td\0e\x1b[31mf\x7fg"));

        foreach (["\n", "\r", "\t", "\0", "\x1b", "\x7f"] as $bad) {
            $this->assertStringNotContainsString($bad, $line);
        }

        $this->assertStringContainsString('a b c d e [31mf g', $line);
    }

    public function testIncludesTheExceptionClass(): void
    {
        $this->assertStringContainsString(\RuntimeException::class, ErrorLog::format(new \RuntimeException('x')));
    }

    public function testWholeLineIsCapped(): void
    {
        $line = ErrorLog::format(new \RuntimeException(str_repeat('a', 5000)));

        $this->assertLessThanOrEqual(300, mb_strlen($line));
    }

    public function testInvalidUtf8DoesNotThrow(): void
    {
        $line = ErrorLog::format(new \RuntimeException("bad \xC3\x28 \xFF\xFE bytes\n"));

        $this->assertIsString($line);
        $this->assertStringNotContainsString("\n", $line);
    }

    public function testNormalMessagePassesThrough(): void
    {
        $this->assertSame(
            'Cookie Manager: InvalidArgumentException: Something went wrong',
            ErrorLog::format(new \InvalidArgumentException('Something went wrong')),
        );
    }
}
