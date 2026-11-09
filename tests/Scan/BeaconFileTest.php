<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use PHPUnit\Framework\TestCase;

final class BeaconFileTest extends TestCase
{
    public function testTheFileTheBeaconControllerServesExistsAndIsNotEmpty(): void
    {
        // ScanBeacon reads dirname(__DIR__, 2) . '/js/cmk-beacon.js' from src/Ajax, i.e. the package's js folder.
        $source = (string) @file_get_contents(dirname(__DIR__, 2) . '/js/cmk-beacon.js');

        $this->assertNotSame('', $source);
        $this->assertStringContainsString('cmk_scan', $source);
    }
}
