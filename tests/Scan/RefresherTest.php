<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Refresher;
use CookieManagerKit\Scan\FetchFailed;
use CookieManagerKit\Scan\SnapshotSource;
use CookieManagerKit\Scan\ClassificationStore;

final class RefresherTest extends TestCase
{
    private static function source(string|\Throwable $result): SnapshotSource
    {
        return new class($result) implements SnapshotSource {
            public function __construct(private string|\Throwable $result) {}

            public function fetch(): string
            {
                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        };
    }

    private static function validCsv(): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['ID', 'Platform', 'Category', 'Cookie / Data Key name', 'Domain', 'Description', 'Retention period', 'Data Controller', 'User Privacy & GDPR Rights Portals', 'Wildcard match'], ',', '"', '');

        for ($i = 0; $i < 60; $i++) {
            fputcsv($out, ['i' . $i, 'P', 'Analytics', 'c' . $i, 'x.com', 'd', '1 year', 'V', '', '0'], ',', '"', '');
        }

        rewind($out);

        return (string) stream_get_contents($out);
    }

    public function testStoresTheRefreshedRowsAndReturnsTheCount(): void
    {
        $store = $this->createMock(ClassificationStore::class);
        $store->expects($this->once())->method('replaceOrigin')->with('refreshed', $this->callback(static fn(array $rows): bool => count($rows) === 60));

        $this->assertSame(60, (new Refresher(self::source(self::validCsv()), $store))->refresh());
    }

    public function testAnInvalidDownloadStoresNothing(): void
    {
        $store = $this->createMock(ClassificationStore::class);
        $store->expects($this->never())->method('replaceOrigin');

        $this->expectException(\RuntimeException::class);

        (new Refresher(self::source('<html>captive portal</html>'), $store))->refresh();
    }

    public function testADownloadFailureStoresNothingAndSurfaces(): void
    {
        $store = $this->createMock(ClassificationStore::class);
        $store->expects($this->never())->method('replaceOrigin');

        $this->expectException(FetchFailed::class);

        (new Refresher(self::source(new FetchFailed('offline')), $store))->refresh();
    }
}
