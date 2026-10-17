<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\CsvCell;
use PHPUnit\Framework\Attributes\DataProvider;

final class CsvCellTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cells(): array
    {
        return [
            'plain' => ['hello', 'hello'],
            'equals' => ['=HYPERLINK("http://evil")', "'=HYPERLINK(\"http://evil\")"],
            'plus' => ['+1+1', "'+1+1"],
            'minus' => ['-2', "'-2"],
            'at' => ['@SUM(A1)', "'@SUM(A1)"],
            'tab' => ["\t=1", "'\t=1"],
            'carriage return' => ["\r=1", "'\r=1"],
            'safe middle' => ['a=b', 'a=b'],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('cells')]
    public function testNeutralise(string $in, string $out): void
    {
        $this->assertSame($out, CsvCell::neutralise($in));
    }

    public function testRowQuotesEscapesAndNeutralises(): void
    {
        $this->assertSame("\"a\",\"he said \"\"hi\"\"\",\"'=1\"\r\n", CsvCell::row(['a', 'he said "hi"', '=1']));
    }

    public function testRowKeepsNewlinesInsideTheQuotes(): void
    {
        $this->assertSame("\"line1\nline2\"\r\n", CsvCell::row(["line1\nline2"]));
    }
}
