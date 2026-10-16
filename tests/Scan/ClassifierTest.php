<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Classifier;

final class ClassifierTest extends TestCase
{
    /**
     * @return array{kind: string, pattern: string, category: string, vendor: string, origin: string}
     */
    private static function entry(string $kind, string $pattern, string $category, string $origin, string $vendor = 'V'): array
    {
        return ['kind' => $kind, 'pattern' => $pattern, 'category' => $category, 'vendor' => $vendor, 'origin' => $origin];
    }

    private static function classifier(array $entries): Classifier
    {
        return new Classifier($entries, static fn(string $name): bool => $name === 'CONCRETE');
    }

    /**
     * @return array{string, string}|null
     */
    private function pair(?\CookieManagerKit\Scan\Suggestion $suggestion): ?array
    {
        return $suggestion === null ? null : [$suggestion->category, $suggestion->vendor];
    }

    public function testMalformedEntriesAreSkipped(): void
    {
        $classifier = self::classifier([
            ['kind' => 'cookie', 'category' => 'x', 'vendor' => 'V', 'origin' => 'override'],
            ['kind' => 'cookie', 'pattern' => 5, 'category' => 'x', 'vendor' => 'V', 'origin' => 'override'],
            ['kind' => 'cookie', 'pattern' => ['_ga'], 'category' => 'x', 'vendor' => 'V', 'origin' => 'override'],
            ['kind' => 'cookie', 'pattern' => '_ga', 'category' => 'x', 'vendor' => 'V', 'origin' => null],
            'garbage',
            null,
            self::entry('cookie', '_ga', 'analytics', 'bundled'),
        ]);

        $this->assertSame('analytics', $classifier->classify('cookie', '_ga', 'e.com')?->category);
    }

    public function testStorageEntriesOutrankCookieEntriesWithinAnOrigin(): void
    {
        $classifier = self::classifier([
            self::entry('cookie', 'cart_item', 'marketing', 'bundled'),
            self::entry('storage', 'cart*', 'necessary', 'bundled'),
        ]);

        $this->assertSame('necessary', $classifier->classify('storage', 'cart_item', 'e.com')?->category);
        $this->assertSame('marketing', $classifier->classify('cookie', 'cart_item', 'e.com')?->category);
    }

    public function testOverrideBeatsRefreshedBeatsBundledEvenWhenTheOlderEntryIsMoreSpecific(): void
    {
        $classifier = self::classifier([
            self::entry('cookie', '_ga', 'analytics', 'bundled', 'Bundled'),
            self::entry('cookie', '_g*', 'marketing', 'refreshed', 'Refreshed'),
            self::entry('cookie', '*', 'preferences', 'override', 'Override'),
        ]);

        $this->assertSame(['preferences', 'Override'], $this->pair($classifier->classify('cookie', '_ga', 'e.com')));
    }

    public function testRefreshedBeatsBundled(): void
    {
        $classifier = self::classifier([
            self::entry('cookie', '_ga', 'analytics', 'bundled'),
            self::entry('cookie', '_ga', 'marketing', 'refreshed'),
        ]);

        $this->assertSame('marketing', $classifier->classify('cookie', '_ga', 'e.com')?->category);
    }

    public function testWithinAnOriginExactBeatsWildcardAndLongerBeatsShorter(): void
    {
        $classifier = self::classifier([
            self::entry('cookie', '_g*', 'marketing', 'bundled'),
            self::entry('cookie', '_ga*', 'preferences', 'bundled'),
            self::entry('cookie', '_gat', 'analytics', 'bundled'),
        ]);

        $this->assertSame('analytics', $classifier->classify('cookie', '_gat', 'e.com')?->category);
        $this->assertSame('preferences', $classifier->classify('cookie', '_ga_X', 'e.com')?->category);
        $this->assertSame('marketing', $classifier->classify('cookie', '_gx', 'e.com')?->category);
    }

    public function testCookieNamesAreCaseSensitiveButHostsAreNot(): void
    {
        $classifier = self::classifier([
            self::entry('cookie', '_ga', 'analytics', 'bundled'),
            self::entry('host', '*.doubleclick.net', 'marketing', 'bundled'),
        ]);

        $this->assertNull($classifier->classify('cookie', '_GA', 'e.com'));
        $this->assertSame('marketing', $classifier->classify('script', 'ads.DoubleClick.net', 'ads.DoubleClick.net')?->category);
    }

    public function testHostKindsMatchOnTheHostNotTheName(): void
    {
        $classifier = self::classifier([self::entry('host', 'cdn.example.net', 'marketing', 'bundled')]);

        foreach (['script', 'iframe', 'image', 'resource'] as $kind) {
            $this->assertSame('marketing', $classifier->classify($kind, 'ignored', 'cdn.example.net')?->category, $kind);
        }

        $this->assertNull($classifier->classify('cookie', 'cdn.example.net', 'e.com'));
    }

    public function testStorageKeysMatchStorageEntriesThenCookieEntries(): void
    {
        $classifier = self::classifier([
            self::entry('cookie', '_ga', 'analytics', 'bundled'),
            self::entry('storage', 'cart', 'necessary', 'bundled'),
        ]);

        $this->assertSame('necessary', $classifier->classify('storage', 'cart', 'e.com')?->category);
        $this->assertSame('analytics', $classifier->classify('storage', '_ga', 'e.com')?->category);
        $this->assertNull($classifier->classify('cookie', 'cart', 'e.com'));
    }

    public function testEssentialConcreteCookiesAreAlwaysNecessaryWhateverAnOverrideSays(): void
    {
        $classifier = self::classifier([self::entry('cookie', 'CONCRETE', 'marketing', 'override')]);

        $this->assertSame(['necessary', 'ConcreteCMS'], $this->pair($classifier->classify('cookie', 'CONCRETE', 'e.com')));
    }

    public function testNoEntryMeansNoSuggestion(): void
    {
        $this->assertNull(self::classifier([])->classify('cookie', 'mystery', 'e.com'));
    }

    public function testHostileNamesAreJustStrings(): void
    {
        $classifier = self::classifier([self::entry('cookie', '_ga', 'analytics', 'bundled')]);

        $this->assertNull($classifier->classify('cookie', '"><img src=x onerror=alert(1)>', 'e.com'));
        $this->assertNull($classifier->classify('cookie', str_repeat('a', 10000), 'e.com'));
    }

    public function testWhenTwoOverridesTieTheEarlierEntryWins(): void
    {
        // ClassificationStore::entries() lists the newest row first, so a re-saved rule beats the one it replaced.
        $classifier = new Classifier([
            ['kind' => 'cookie', 'pattern' => '_x', 'category' => 'marketing', 'vendor' => 'New', 'origin' => 'override'],
            ['kind' => 'cookie', 'pattern' => '_x', 'category' => 'analytics', 'vendor' => 'Old', 'origin' => 'override'],
        ], static fn(string $name): bool => false);

        $this->assertSame('marketing', $classifier->classify('cookie', '_x', 'e.com')?->category);
    }
}
