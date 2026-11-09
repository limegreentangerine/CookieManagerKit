<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\BeaconReport;
use PHPUnit\Framework\Attributes\DataProvider;

final class BeaconReportTest extends TestCase
{
    private static function body(array $overrides = []): string
    {
        return json_encode($overrides + ['c' => ['_ga', 'sid'], 's' => ['cart'], 'h' => ['cdn.example.net'], 'p' => '/about'], JSON_THROW_ON_ERROR);
    }

    public function testParsesAValidReport(): void
    {
        $report = BeaconReport::parse('application/json', self::body());

        $this->assertNotNull($report);
        $this->assertSame('/about', $report->path);
        $this->assertSame(['_ga', 'sid'], $report->cookies);
        $this->assertSame(['cart'], $report->storage);
        $this->assertSame(['cdn.example.net'], $report->hosts);
    }

    public function testTheContentTypeMayCarryACharset(): void
    {
        $this->assertNotNull(BeaconReport::parse('application/json; charset=utf-8', self::body()));
    }

    /**
     * @return array<string, array{?string, string}>
     */
    public static function rejected(): array
    {
        $ok = ['c' => [], 's' => [], 'h' => [], 'p' => '/'];

        return [
            'no content type' => [null, json_encode($ok)],
            'form content type' => ['application/x-www-form-urlencoded', json_encode($ok)],
            'text/plain' => ['text/plain', json_encode($ok)],
            'empty body' => ['application/json', ''],
            'not json' => ['application/json', '{not json'],
            'a list' => ['application/json', '[]'],
            'a scalar' => ['application/json', '"x"'],
            'missing key' => ['application/json', json_encode(['c' => [], 's' => [], 'p' => '/'])],
            'extra key' => ['application/json', json_encode($ok + ['x' => 1])],
            'values key' => ['application/json', json_encode($ok + ['v' => ['a' => 'SECRET']])],
            'cookies not a list' => ['application/json', json_encode(['c' => 'x'] + $ok)],
            'cookies an object' => ['application/json', json_encode(['c' => ['a' => 'b']] + $ok)],
            'non-string cookie' => ['application/json', json_encode(['c' => [1]] + $ok)],
            'nested cookie' => ['application/json', json_encode(['c' => [['a']]] + $ok)],
            'non-string host' => ['application/json', json_encode(['h' => [null]] + $ok)],
            'path not a string' => ['application/json', json_encode(['p' => 5] + $ok)],
            'path without slash' => ['application/json', json_encode(['p' => 'about'] + $ok)],
            'protocol relative path' => ['application/json', json_encode(['p' => '//evil.example.net/x'] + $ok)],
            'full url as path' => ['application/json', json_encode(['p' => 'https://evil.example.net/'] + $ok)],
            'path with a newline' => ['application/json', json_encode(['p' => "/a\nb"] + $ok)],
            'overlong path' => ['application/json', json_encode(['p' => '/' . str_repeat('a', 600)] + $ok)],
            'too many cookies' => ['application/json', json_encode(['c' => array_map(static fn(int $i): string => 'c' . $i, range(1, 101))] + $ok)],
            'too deeply nested' => ['application/json', '{"c":[],"s":[],"h":[],"p":"/","z":{"a":{"b":{"c":{}}}}}'],
        ];
    }

    #[DataProvider('rejected')]
    public function testStructuralViolationsRejectTheWholeReport(?string $contentType, string $body): void
    {
        $this->assertNull(BeaconReport::parse($contentType, $body));
    }

    public function testAnOversizeBodyIsRejected(): void
    {
        $padding = str_repeat('a', BeaconReport::MAX_BYTES);

        $this->assertNull(BeaconReport::parse('application/json', self::body(['p' => '/' . $padding])));
    }

    public function testExactlyOneHundredItemsIsAccepted(): void
    {
        $names = array_map(static fn(int $i): string => 'c' . $i, range(1, 100));

        $report = BeaconReport::parse('application/json', self::body(['c' => $names]));

        $this->assertNotNull($report);
        $this->assertCount(100, $report->cookies);
    }

    public function testInvalidItemsAreSkippedNotFatal(): void
    {
        $report = BeaconReport::parse('application/json', self::body([
            'c' => ['good', '', "\u{0000}\u{0001}", '   ', 'also_good'],
            'h' => ['cdn.example.net', 'not a host', '[::1]', '<script>.example.com', 'localhost', 'cdn.example.net', 'CDN.Example.net'],
        ]));

        $this->assertNotNull($report);
        $this->assertSame(['good', 'also_good'], $report->cookies);
        $this->assertSame(['cdn.example.net'], $report->hosts);
    }

    public function testHostileNamesAreKeptVerbatimAndCapped(): void
    {
        $report = BeaconReport::parse('application/json', self::body(['c' => ['"><img src=x onerror=alert(1)>', str_repeat('n', 5000)]]));

        $this->assertNotNull($report);
        $this->assertSame('"><img src=x onerror=alert(1)>', $report->cookies[0]);
        $this->assertSame(128, strlen($report->cookies[1]));
    }

    public function testDuplicatesAreRemoved(): void
    {
        $report = BeaconReport::parse('application/json', self::body(['c' => ['a', 'a', 'b'], 's' => ['k', 'k']]));

        $this->assertNotNull($report);
        $this->assertSame(['a', 'b'], $report->cookies);
        $this->assertSame(['k'], $report->storage);
    }
}
