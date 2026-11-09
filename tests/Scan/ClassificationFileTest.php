<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\ClassificationFile;
use PHPUnit\Framework\Attributes\DataProvider;

final class ClassificationFileTest extends TestCase
{
    private const HEADER = "kind,pattern,category,vendor,description,duration\n";

    /**
     * @param list<array<int, string>> $extra
     */
    private static function ocd(array $extra = [], int $count = 60): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['ID', 'Platform', 'Category', 'Cookie / Data Key name', 'Domain', 'Description', 'Retention period', 'Data Controller', 'User Privacy & GDPR Rights Portals', 'Wildcard match'], ',', '"', '');

        for ($i = 0; $i < $count; $i++) {
            fputcsv($out, ['id' . $i, 'Platform', 'Analytics', 'cookie_' . $i, 'example.com', 'Does a thing', '1 year', 'Example Inc', 'https://example.com/privacy', '0'], ',', '"', '');
        }

        foreach ($extra as $row) {
            fputcsv($out, $row, ',', '"', '');
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }

    public function testParsesAValidBundledFile(): void
    {
        $rows = ClassificationFile::parseBundled(self::HEADER . "cookie,_ga,analytics,Google,\"Tells visitors apart, per browser\",2 years\nhost,*.doubleclick.net,marketing,Google,Ad serving,\n");

        $this->assertSame([
            ['kind' => 'cookie', 'pattern' => '_ga', 'category' => 'analytics', 'vendor' => 'Google', 'description' => 'Tells visitors apart, per browser', 'duration' => '2 years'],
            ['kind' => 'host', 'pattern' => '*.doubleclick.net', 'category' => 'marketing', 'vendor' => 'Google', 'description' => 'Ad serving', 'duration' => ''],
        ], $rows);
    }

    public function testAHeaderOnlyFileIsValidAndEmpty(): void
    {
        $this->assertSame([], ClassificationFile::parseBundled(self::HEADER));
    }

    public function testAByteOrderMarkIsTolerated(): void
    {
        $this->assertCount(1, (array) ClassificationFile::parseBundled("\xEF\xBB\xBF" . self::HEADER . "cookie,_ga,analytics,G,d,1 day\n"));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalid(): array
    {
        $h = "kind,pattern,category,vendor,description,duration\n";

        return [
            'empty' => [''],
            'wrong header' => ["kind,pattern,category\ncookie,_ga,analytics\n"],
            'extra column' => [$h . "cookie,_ga,analytics,G,d,1 day,extra\n"],
            'short row' => [$h . "cookie,_ga,analytics\n"],
            'unknown kind' => [$h . "script,_ga,analytics,G,d,1\n"],
            'pattern with a space' => [$h . "cookie,_g a,analytics,G,d,1\n"],
            'pattern with markup' => [$h . "cookie,<b>,analytics,G,d,1\n"],
            'empty pattern' => [$h . "cookie,,analytics,G,d,1\n"],
            'overlong pattern' => [$h . 'cookie,' . str_repeat('a', 129) . ",analytics,G,d,1\n"],
            'too many wildcards' => [$h . "cookie,*a*b*c*d,analytics,G,d,1\n"],
            'bare wildcard' => [$h . "cookie,*,analytics,G,d,1\n"],
            'double wildcard' => [$h . "cookie,**,analytics,G,d,1\n"],
            'too few literals' => [$h . "cookie,ab*,analytics,G,d,1\n"],
            'tld host wildcard' => [$h . "host,*.com,marketing,G,d,\n"],
            'second level tld host wildcard' => [$h . "host,*.co.uk,marketing,G,d,\n"],
            'invalid utf-8' => [$h . "cookie,_ga,analytics,G,\xC3\x28,1\n"],
            'duplicate pattern' => [$h . "cookie,_ga,analytics,G,d,1\ncookie,_ga,marketing,G,d,1\n"],
            'category not a handle' => [$h . "cookie,_ga,Analytics!,G,d,1\n"],
            'one bad row spoils the file' => [$h . "cookie,_ga,analytics,G,d,1\ncookie,bad row,analytics,G,d,1\n"],
        ];
    }

    #[DataProvider('invalid')]
    public function testAnythingWrongRejectsTheWholeBundledFile(string $csv): void
    {
        $this->assertNull(ClassificationFile::parseBundled($csv));
    }

    public function testAnOversizeFileIsRejected(): void
    {
        $this->assertNull(ClassificationFile::parseBundled(self::HEADER . str_repeat('x', ClassificationFile::MAX_BYTES)));
    }

    public function testTooManyRowsAreRejected(): void
    {
        $rows = str_repeat("cookie,_ga,analytics,G,d,1\n", ClassificationFile::MAX_ROWS + 1);

        $this->assertNull(ClassificationFile::parseBundled(self::HEADER . $rows));
    }

    public function testVendorAndDescriptionAreReducedToPlainText(): void
    {
        $rows = (array) ClassificationFile::parseBundled(self::HEADER . "cookie,_ga,analytics,<b>Google</b>,\"<script>x</script>Visitors\",1 day\n");

        $this->assertSame('Google', $rows[0]['vendor']);
        $this->assertSame('xVisitors', $rows[0]['description']);
    }

    public function testHostPatternsAreLowerCased(): void
    {
        $rows = (array) ClassificationFile::parseBundled(self::HEADER . "host,WWW.Example.COM,marketing,V,d,\n");

        $this->assertSame('www.example.com', $rows[0]['pattern']);
    }

    public function testTheShippedBaselineIsValidAndUseful(): void
    {
        $rows = ClassificationFile::parseBundled((string) file_get_contents(ClassificationFile::baselinePath()));

        $this->assertNotNull($rows);
        $this->assertGreaterThan(60, count($rows));

        $patterns = array_map(static fn(array $row): string => $row['kind'] . ':' . $row['pattern'], $rows);

        foreach (['cookie:_ga', 'cookie:_fbp', 'host:www.googletagmanager.com', 'host:connect.facebook.net', 'host:js.stripe.com'] as $expected) {
            $this->assertContains($expected, $patterns);
        }

        $this->assertSame(count($patterns), count(array_unique($patterns)), 'no duplicate patterns');

        foreach ($rows as $row) {
            $this->assertContains($row['category'], ['necessary', 'preferences', 'analytics', 'marketing']);
        }
    }

    public function testParsesTheOpenCookieDatabaseColumns(): void
    {
        $rows = (array) ClassificationFile::parseOpenCookieDatabase(self::ocd([
            ['x1', 'Matomo', 'Analytics', '_pk_id.', 'example.com', 'Visitor id', '13 months', 'Matomo', '', '1'],
            ['x2', 'Cloudflare', 'Security', '__cf_bm', 'example.com', 'Bot management', '30 minutes', 'Cloudflare', '', '0'],
            ['x3', 'Site', 'Functional', 'lang', 'example.com', 'Language', 'Session', 'Site', '', '0'],
            ['x4', 'Ads', 'Marketing', 'ad_id', 'example.com', 'Ads', '1 year', 'Ad Co', '', '0'],
            ['x5', 'Site', 'Personalization', 'theme', 'example.com', 'Theme', '1 year', 'Site', '', '0'],
            ['x6', 'Site', 'Necessary', 'session_id', 'example.com', 'Session', 'Session', 'Site', '', '0'],
        ]));

        $by = [];

        foreach ($rows as $row) {
            $by[$row['pattern']] = $row;
        }

        $this->assertSame(['cookie', 'analytics', 'Matomo', '13 months'], [$by['_pk_id.*']['kind'], $by['_pk_id.*']['category'], $by['_pk_id.*']['vendor'], $by['_pk_id.*']['duration']]);
        $this->assertSame('necessary', $by['__cf_bm']['category']);
        $this->assertSame('preferences', $by['lang']['category']);
        $this->assertSame('marketing', $by['ad_id']['category']);
        $this->assertSame('preferences', $by['theme']['category']);
        $this->assertSame('necessary', $by['session_id']['category']);
    }

    public function testOnlyExactNamesCanBeNecessaryInTheOpenCookieDatabase(): void
    {
        $rows = (array) ClassificationFile::parseOpenCookieDatabase(self::ocd([
            ['n1', 'P', 'Security', 'exact_name', 'x.com', 'd', '1y', 'V', '', '0'],
            ['n2', 'P', 'Security', 'broad_family_', 'x.com', 'd', '1y', 'V', '', '1'],
        ]));

        $by = array_column($rows, null, 'pattern');

        $this->assertSame('necessary', $by['exact_name']['category']);
        $this->assertArrayNotHasKey('broad_family_*', $by);
    }

    public function testTooShortWildcardsAreUnusableInTheOpenCookieDatabase(): void
    {
        $rows = (array) ClassificationFile::parseOpenCookieDatabase(self::ocd([
            ['w1', 'P', 'Marketing', 'ab', 'x.com', 'd', '1y', 'V', '', '1'],
            ['w2', 'P', 'Marketing', '*', 'x.com', 'd', '1y', 'V', '', '0'],
        ]));

        $patterns = array_column($rows, 'pattern');

        $this->assertNotContains('ab*', $patterns);
        $this->assertNotContains('*', $patterns);
    }

    public function testTheOpenCookieDatabaseNeedsItsHeaderColumns(): void
    {
        $this->assertNull(ClassificationFile::parseOpenCookieDatabase("a,b,c\n1,2,3\n"));
        $this->assertNull(ClassificationFile::parseOpenCookieDatabase(''));
    }

    public function testTooSmallAnOpenCookieDatabaseFileIsRejectedAsSuspicious(): void
    {
        $this->assertNull(ClassificationFile::parseOpenCookieDatabase(self::ocd([], 10)));
    }

    public function testAFileWhoseRowsMostlyFailIsRejected(): void
    {
        $bad = [];

        for ($i = 0; $i < 40; $i++) {
            $bad[] = ['b' . $i, 'P', 'Alien', 'name' . $i, 'x.com', 'd', '1y', 'V', '', '0'];
        }

        $this->assertNull(ClassificationFile::parseOpenCookieDatabase(self::ocd($bad, 60)));
    }

    public function testASmallShareOfUnusableRowsIsDropped(): void
    {
        $rows = (array) ClassificationFile::parseOpenCookieDatabase(self::ocd([
            ['b1', 'P', 'Alien', 'dropped_one', 'x.com', 'd', '1y', 'V', '', '0'],
            ['b2', 'P', 'Analytics', 'has a space', 'x.com', 'd', '1y', 'V', '', '0'],
        ]));

        $patterns = array_column($rows, 'pattern');

        $this->assertNotContains('dropped_one', $patterns);
        $this->assertNotContains('has a space', $patterns);
        $this->assertCount(60, $rows);
    }

    public function testDuplicateOpenCookieDatabaseNamesKeepTheFirst(): void
    {
        $rows = (array) ClassificationFile::parseOpenCookieDatabase(self::ocd([
            ['d1', 'P', 'Analytics', 'dup', 'x.com', 'first', '1y', 'First', '', '0'],
            ['d2', 'P', 'Marketing', 'dup', 'x.com', 'second', '1y', 'Second', '', '0'],
        ]));

        $dups = array_values(array_filter($rows, static fn(array $row): bool => $row['pattern'] === 'dup'));

        $this->assertCount(1, $dups);
        $this->assertSame('First', $dups[0]['vendor']);
    }
}
