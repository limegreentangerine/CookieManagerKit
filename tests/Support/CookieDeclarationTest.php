<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\CookieDeclaration;

final class CookieDeclarationTest extends TestCase
{
    private function snapshot(): array
    {
        return ['categories' => [
            ['handle' => 'necessary', 'necessary' => true, 'sort' => 0, 'content' => ['en_GB' => ['title' => 'Necessary', 'description' => 'Needed']], 'services' => []],
            ['handle' => 'analytics', 'necessary' => false, 'sort' => 20, 'content' => ['en_GB' => ['title' => 'Analytics', 'description' => 'Measures']], 'services' => [
                ['name' => 'GA', 'vendor' => 'Google', 'content' => [], 'domains' => ['google-analytics.com'], 'cookies' => [
                    ['name' => '_ga', 'days' => 730, 'content' => ['en_GB' => 'Counts visits']],
                    ['name' => '_gid', 'days' => 0, 'content' => []],
                ]],
            ]],
            ['handle' => 'marketing', 'necessary' => false, 'sort' => 30, 'content' => [], 'services' => [
                ['name' => 'Pixel', 'vendor' => '', 'content' => [], 'domains' => [], 'cookies' => []],
            ]],
        ]];
    }

    private function build(string $locale = 'en_GB', array $essential = []): array
    {
        return CookieDeclaration::build($this->snapshot(), $locale, 'en_GB', $essential, static fn(int $days): string => $days === 0 ? 'Session' : $days . ' days');
    }

    public function testCategoriesWithoutCookiesAreOmittedButNecessaryAlwaysListsTheEssentialCookies(): void
    {
        $sections = $this->build('en_GB', [['name' => 'CONCRETE', 'purpose' => 'Session', 'duration' => 'Session']]);

        $this->assertSame(['necessary', 'analytics'], array_column($sections, 'handle'));
        $this->assertSame('CONCRETE', $sections[0]['cookies'][0]['name']);
        $this->assertSame('', $sections[0]['cookies'][0]['provider']);
    }

    public function testWithNoCookiesDefinedAndNoEssentialCookiesNothingIsListed(): void
    {
        $this->assertSame([], CookieDeclaration::build(['categories' => []], 'en_GB', 'en_GB', [], static fn(int $d): string => (string) $d));
    }

    public function testRowsCarryProviderPurposeAndDuration(): void
    {
        $rows = $this->build()[0]['cookies'];

        $this->assertSame(['name' => '_ga', 'provider' => 'Google', 'purpose' => 'Counts visits', 'duration' => '730 days'], $rows[0]);
        $this->assertSame('Session', $rows[1]['duration']);
    }

    public function testAMissingTranslationFallsBackToTheDefaultLocaleThenToNothing(): void
    {
        $rows = $this->build('fr_FR')[0]['cookies'];

        $this->assertSame('Counts visits', $rows[0]['purpose']);
        $this->assertSame('', $rows[1]['purpose']);
        $this->assertSame('Analytics', $this->build('fr_FR')[0]['title']);
    }

    public function testTheProviderFallsBackToTheServiceName(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['categories'][1]['services'][0]['vendor'] = '';

        $rows = CookieDeclaration::build($snapshot, 'en_GB', 'en_GB', [], static fn(int $d): string => (string) $d)[0]['cookies'];

        $this->assertSame('GA', $rows[0]['provider']);
    }

    public function testAnInvalidHandleOrMalformedEntriesAreDropped(): void
    {
        $snapshot = ['categories' => ['junk', ['handle' => 'Bad Handle!', 'services' => [['cookies' => [['name' => 'x', 'days' => 1]]]]], ['handle' => 'ok', 'content' => [], 'services' => [['name' => 'S', 'cookies' => [['name' => '', 'days' => 1], ['name' => 'real', 'days' => 3]]]]]]];

        $sections = CookieDeclaration::build($snapshot, 'en_GB', 'en_GB', [], static fn(int $d): string => (string) $d);

        $this->assertSame(['ok'], array_column($sections, 'handle'));
        $this->assertSame(['real'], array_column($sections[0]['cookies'], 'name'));
    }
}
