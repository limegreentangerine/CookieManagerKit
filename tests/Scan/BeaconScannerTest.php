<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Finding;
use CookieManagerKit\Scan\ScanContext;
use CookieManagerKit\Scan\BeaconReport;
use CookieManagerKit\Scan\BeaconScanner;

final class BeaconScannerTest extends TestCase
{
    /**
     * @param list<Finding> $found
     *
     * @return list<string>
     */
    private static function summary(array $found): array
    {
        $rows = array_map(static fn(Finding $f): string => $f->kind . ' ' . $f->name . ' ' . $f->host, $found);
        sort($rows);

        return $rows;
    }

    public function testTurnsAReportIntoCookieStorageAndHostFindings(): void
    {
        $report = new BeaconReport('/about', ['_ga'], ['cart'], ['cdn.example.net', 'www.example.com', 'example.com']);

        $result = (new BeaconScanner())->scan(new ScanContext('example.com', [], $report));

        $this->assertSame([
            'cookie _ga example.com',
            'resource cdn.example.net cdn.example.net',
            'storage cart example.com',
        ], self::summary($result->findings));
        $this->assertSame('/about', $result->findings[0]->page);
        $this->assertSame([1, 0], [$result->pagesScanned, $result->pagesFailed]);
    }

    public function testThePackagesOwnScanCookiesAreNeverFindings(): void
    {
        $report = new BeaconReport('/', ['cmk_scan', 'cmk_scan_token', '_ga'], [], []);

        $result = (new BeaconScanner())->scan(new ScanContext('example.com', [], $report));

        $this->assertSame(['cookie _ga example.com'], self::summary($result->findings));
    }

    public function testWithoutAReportThereIsNothing(): void
    {
        $result = (new BeaconScanner())->scan(new ScanContext('example.com'));

        $this->assertSame([], $result->findings);
        $this->assertSame([0, 0], [$result->pagesScanned, $result->pagesFailed]);
    }
}
