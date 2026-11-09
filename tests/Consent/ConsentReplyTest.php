<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Consent;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Consent\ConsentReply;

final class ConsentReplyTest extends TestCase
{
    public function testSuccessCarriesTheIdWhenThereIsOne(): void
    {
        $this->assertSame(['ok' => true, 'id' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301'], ConsentReply::body(200, '3f2504e0-4f89-11d3-9a0c-0305e82c3301'));
        $this->assertSame(['ok' => true], ConsentReply::body(200, null));
    }

    public function testEveryFailureIsTheSameFixedBody(): void
    {
        foreach ([400, 403, 405, 429, 500] as $status) {
            $this->assertSame(['error' => 'invalid'], ConsentReply::body($status, '3f2504e0-4f89-11d3-9a0c-0305e82c3301'));
        }
    }

    public function testAMalformedIdIsNeverSent(): void
    {
        $this->assertSame(['ok' => true], ConsentReply::body(200, '"><script>'));
    }
}
