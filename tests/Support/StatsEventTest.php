<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\StatsEvent;
use PHPUnit\Framework\Attributes\DataProvider;

final class StatsEventTest extends TestCase
{
    private const JSON = 'application/json';

    public function testAcceptsEveryKnownEvent(): void
    {
        foreach (StatsEvent::EVENTS as $event) {
            $parsed = StatsEvent::parse(self::JSON, json_encode(['v' => 3, 'e' => $event, 'l' => 'en_GB'], JSON_THROW_ON_ERROR));

            $this->assertSame(['v' => 3, 'e' => $event, 'l' => 'en_GB'], $parsed, $event);
        }
    }

    public function testAcceptsACharsetOnTheContentType(): void
    {
        $this->assertNotNull(StatsEvent::parse('application/json; charset=utf-8', '{"v":1,"e":"shown","l":"en"}'));
    }

    /**
     * @return array<string, array{0: ?string, 1: string}>
     */
    public static function rejected(): array
    {
        return [
            'no content type' => [null, '{"v":1,"e":"shown","l":"en"}'],
            'form content type' => ['application/x-www-form-urlencoded', '{"v":1,"e":"shown","l":"en"}'],
            'text content type' => ['text/plain', '{"v":1,"e":"shown","l":"en"}'],
            'empty body' => ['application/json', ''],
            'not json' => ['application/json', 'shown'],
            'a list' => ['application/json', '["shown"]'],
            'empty object' => ['application/json', '{}'],
            'a scalar' => ['application/json', '5'],
            'missing locale' => ['application/json', '{"v":1,"e":"shown"}'],
            'missing event' => ['application/json', '{"v":1,"l":"en"}'],
            'missing version' => ['application/json', '{"e":"shown","l":"en"}'],
            'extra key' => ['application/json', '{"v":1,"e":"shown","l":"en","x":1}'],
            'unknown event' => ['application/json', '{"v":1,"e":"opened","l":"en"}'],
            'event wrong case' => ['application/json', '{"v":1,"e":"SHOWN","l":"en"}'],
            'event is html' => ['application/json', '{"v":1,"e":"<script>","l":"en"}'],
            'event is a list' => ['application/json', '{"v":1,"e":["shown"],"l":"en"}'],
            'event is null' => ['application/json', '{"v":1,"e":null,"l":"en"}'],
            'version as string' => ['application/json', '{"v":"1","e":"shown","l":"en"}'],
            'version as float' => ['application/json', '{"v":1.5,"e":"shown","l":"en"}'],
            'version zero' => ['application/json', '{"v":0,"e":"shown","l":"en"}'],
            'version negative' => ['application/json', '{"v":-1,"e":"shown","l":"en"}'],
            'version too large' => ['application/json', '{"v":1000001,"e":"shown","l":"en"}'],
            'locale is html' => ['application/json', '{"v":1,"e":"shown","l":"<script>"}'],
            'locale traversal' => ['application/json', '{"v":1,"e":"shown","l":"../../x"}'],
            'locale with newline' => ['application/json', '{"v":1,"e":"shown","l":"en_GB\n"}'],
            'locale too long' => ['application/json', '{"v":1,"e":"shown","l":"' . str_repeat('a', 40) . '"}'],
            'locale not a string' => ['application/json', '{"v":1,"e":"shown","l":5}'],
            'nested too deep' => ['application/json', '{"v":1,"e":"shown","l":"en","d":[[[[1]]]]}'],
        ];
    }

    #[DataProvider('rejected')]
    public function testRejectsAnythingThatDeviates(?string $contentType, string $body): void
    {
        $this->assertNull(StatsEvent::parse($contentType, $body));
    }

    public function testRejectsABodyOverTheSizeCap(): void
    {
        $body = '{"v":1,"e":"shown","l":"en","pad":"' . str_repeat('a', StatsEvent::MAX_BYTES) . '"}';

        $this->assertNull(StatsEvent::parse(self::JSON, $body));
    }
}
