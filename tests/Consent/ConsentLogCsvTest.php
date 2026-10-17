<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Consent;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Consent\ConsentLogCsv;

final class ConsentLogCsvTest extends TestCase
{
    private function row(array $over = []): array
    {
        return $over + [
            'createdAt' => 1760616000,
            'consentId' => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
            'version' => 2,
            'categories' => ['necessary', 'analytics'],
            'trigger' => 'reconsent',
            'locale' => 'en_GB',
            'userAgentFamily' => 'Chrome/desktop',
            'reason' => 'Added GA to analytics',
        ];
    }

    public function testHeaderAndRow(): void
    {
        $csv = ConsentLogCsv::render([$this->row()]);
        $lines = explode("\r\n", rtrim($csv, "\r\n"));

        $this->assertSame('"Date (UTC)","Consent ID","Policy version","Categories","Trigger","Locale","User agent family","Re-consent reason"', $lines[0]);
        $this->assertSame('"2025-10-16 12:00:00","3f2504e0-4f89-11d3-9a0c-0305e82c3301","2","necessary analytics","reconsent","en_GB","Chrome/desktop","Added GA to analytics"', $lines[1]);
    }

    public function testTheReasonIsNeutralisedAgainstFormulaInjection(): void
    {
        // Review focus 5: a reason pre-filled from a scanner finding that starts with "=".
        $csv = ConsentLogCsv::render([$this->row(['reason' => '=HYPERLINK("http://evil","x")'])]);

        $this->assertStringContainsString("\"'=HYPERLINK(\"\"http://evil\"\",\"\"x\"\")\"", $csv);
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
    }

    public function testEveryFreeTextCellIsNeutralised(): void
    {
        $csv = ConsentLogCsv::render([$this->row(['locale' => '=1', 'userAgentFamily' => '+2', 'trigger' => '-3'])]);

        $this->assertStringContainsString("\"'=1\"", $csv);
        $this->assertStringContainsString("\"'+2\"", $csv);
        $this->assertStringContainsString("\"'-3\"", $csv);
    }

    public function testAnEmptyReasonIsAnEmptyCell(): void
    {
        $this->assertStringContainsString(',""' . "\r\n", ConsentLogCsv::render([$this->row(['reason' => ''])]));
    }

    public function testNoRowsIsJustTheHeader(): void
    {
        $this->assertSame(1, substr_count(ConsentLogCsv::render([]), "\r\n"));
    }

    public function testTheRowCap(): void
    {
        $this->assertSame(100000, ConsentLogCsv::MAX_ROWS);
    }
}
