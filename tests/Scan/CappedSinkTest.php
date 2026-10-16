<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\CappedSink;
use CookieManagerKit\Scan\FetchFailed;

final class CappedSinkTest extends TestCase
{
    public function testWritingExactlyTheCapIsFineAndReadable(): void
    {
        $sink = new CappedSink(10);
        $sink->write('12345');
        $sink->write('67890');

        $this->assertSame('1234567890', (string) $sink);
    }

    public function testTheWriteThatPassesTheCapThrows(): void
    {
        $sink = new CappedSink(10);
        $sink->write('1234567890');

        $this->expectException(FetchFailed::class);

        $sink->write('1');
    }

    public function testASingleOversizeWriteThrowsBeforeBuffering(): void
    {
        $sink = new CappedSink(4);

        try {
            $sink->write('12345');
            $this->fail('accepted an oversize write');
        } catch (FetchFailed) {
            $this->assertSame('', (string) $sink);
        }
    }
}
