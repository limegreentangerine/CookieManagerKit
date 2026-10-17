<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Translation;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Translation\TranslationCsv;

final class TranslationCsvTest extends TestCase
{
    /** @var array<string, array<string, string>> */
    private array $values = [];

    /** @return array{0: array<string, array<string, string>>, 1: list<\CookieManagerKit\Translation\Slot>} */
    private function fixture(): array
    {
        $this->values = [
            'banner||title' => ['en_GB' => 'Hello', 'fr_FR' => ''],
            'banner||body' => ['en_GB' => "Line one\nLine \"two\"", 'fr_FR' => ''],
            'cookie|3|purpose' => ['en_GB' => '=HYPERLINK("http://evil")', 'fr_FR' => ''],
            'service|2|description' => ['en_GB' => 'Done', 'fr_FR' => 'Fait'],
        ];

        return [$this->values, FakeSlots::make($this->values, ['banner||title' => 'banner', 'banner||body' => 'banner', 'cookie|3|purpose' => 'cookie', 'service|2|description' => 'service'])];
    }

    private function body(string $locale, array $lines): string
    {
        return "\xEF\xBB\xBF" . '"# locale=' . $locale . '"' . "\r\n" . '"key","area","default_text","translation"' . "\r\n" . implode('', $lines);
    }

    public function testExportListsOnlyMissingFieldsWithAHeaderAndLocaleLine(): void
    {
        [, $slots] = $this->fixture();
        $csv = TranslationCsv::export($slots, 'fr_FR', 'en_GB');

        $this->assertStringStartsWith("\xEF\xBB\xBF\"# locale=fr_FR\"\r\n\"key\",\"area\",\"default_text\",\"translation\"\r\n", $csv);
        $this->assertStringContainsString('"banner||title","banner","Hello",""', $csv);
        $this->assertStringNotContainsString('service|2', $csv);
    }

    public function testExportNeutralisesFormulaCellsAndKeepsMultilineText(): void
    {
        [, $slots] = $this->fixture();
        $csv = TranslationCsv::export($slots, 'fr_FR', 'en_GB');

        $this->assertStringContainsString("\"'=HYPERLINK(\"\"http://evil\"\")\"", $csv);
        $this->assertStringContainsString("\"Line one\nLine \"\"two\"\"\"", $csv);
    }

    public function testAnExportedFileParsesBackToTheSameKeys(): void
    {
        [, $slots] = $this->fixture();
        $rows = TranslationCsv::parse(TranslationCsv::export($slots, 'fr_FR', 'en_GB'), 'fr_FR');

        $this->assertSame(['banner||title', 'banner||body', 'cookie|3|purpose'], array_column($rows, 'key'));
        $this->assertSame(['', '', ''], array_column($rows, 'value'));
    }

    public function testParseReadsTranslationsIncludingQuotesAndNewlines(): void
    {
        $rows = TranslationCsv::parse($this->body('fr_FR', ["\"banner||body\",\"banner\",\"x\",\"Ligne un\nLigne \"\"deux\"\"\"\r\n"]), 'fr_FR');

        $this->assertSame([['key' => 'banner||body', 'value' => "Ligne un\nLigne \"deux\""]], $rows);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejected')]
    public function testAMalformedFileIsRejected(string $csv): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TranslationCsv::parse($csv, 'fr_FR');
    }

    public static function rejected(): iterable
    {
        $header = '"key","area","default_text","translation"' . "\r\n";

        yield 'empty' => [''];
        yield 'no locale line' => [$header . '"a","b","c","d"' . "\r\n"];
        yield 'wrong locale' => ['"# locale=de_DE"' . "\r\n" . $header];
        yield 'wrong header' => ['"# locale=fr_FR"' . "\r\n" . '"id","text"' . "\r\n"];
        yield 'short row' => ['"# locale=fr_FR"' . "\r\n" . $header . '"banner||title","banner"' . "\r\n"];
        yield 'empty key' => ['"# locale=fr_FR"' . "\r\n" . $header . '"","banner","x","y"' . "\r\n"];
        yield 'duplicate key' => ['"# locale=fr_FR"' . "\r\n" . $header . '"banner||title","banner","x","y"' . "\r\n" . '"banner||title","banner","x","z"' . "\r\n"];
        yield 'too big' => ['"# locale=fr_FR"' . "\r\n" . $header . str_repeat('x', TranslationCsv::MAX_BYTES)];
    }

    public function testTooManyRowsAreRejected(): void
    {
        $lines = [];
        for ($i = 0; $i <= TranslationCsv::MAX_ROWS; $i++) {
            $lines[] = '"k' . $i . '","a","d","t"' . "\r\n";
        }

        $this->expectException(\InvalidArgumentException::class);

        TranslationCsv::parse($this->body('fr_FR', $lines), 'fr_FR');
    }

    public function testApplyWritesNonBlankTranslationsAndCountsTheRest(): void
    {
        [, $slots] = $this->fixture();

        $result = TranslationCsv::apply($slots, [['key' => 'banner||title', 'value' => 'Bonjour'], ['key' => 'banner||body', 'value' => '']], 'fr_FR', 'en_GB');

        $this->assertSame(['applied' => 1, 'skipped' => 1], $result);
        $this->assertSame('Bonjour', $this->values['banner||title']['fr_FR']);
        $this->assertSame('', $this->values['banner||body']['fr_FR']);
    }

    public function testApplyMayOverwriteAnExistingTranslation(): void
    {
        [, $slots] = $this->fixture();

        TranslationCsv::apply($slots, [['key' => 'service|2|description', 'value' => 'Terminé']], 'fr_FR', 'en_GB');

        $this->assertSame('Terminé', $this->values['service|2|description']['fr_FR']);
    }

    public function testAnUnknownKeyRejectsTheWholeFileAndWritesNothing(): void
    {
        [, $slots] = $this->fixture();

        try {
            TranslationCsv::apply($slots, [['key' => 'banner||title', 'value' => 'Bonjour'], ['key' => 'banner||nope', 'value' => 'x']], 'fr_FR', 'en_GB');
            $this->fail('Expected an exception.');
        } catch (\InvalidArgumentException) {
            $this->assertSame('', $this->values['banner||title']['fr_FR']);
        }
    }

    public function testTheDefaultLocaleCanNeverBeTheTarget(): void
    {
        [, $slots] = $this->fixture();

        $this->expectException(\InvalidArgumentException::class);

        TranslationCsv::apply($slots, [['key' => 'banner||title', 'value' => 'Changed']], 'en_GB', 'en_GB');
    }

    public function testApplyDoesNotCountValuesThatSanitiseToNothing(): void
    {
        [, $slots] = $this->fixture();

        $result = TranslationCsv::apply($slots, [['key' => 'banner||title', 'value' => "<b></b>\x01"], ['key' => 'banner||body', 'value' => 'Ok']], 'fr_FR', 'en_GB');

        $this->assertSame(['applied' => 1, 'skipped' => 1], $result);
    }

    public function testExportKeepsAnApostropheLeadingCellAndNeutralisesOtherLeaders(): void
    {
        $values = ['banner||title' => ['en_GB' => "'quoted", 'fr_FR' => ''], 'banner||body' => ['en_GB' => '-1 off', 'fr_FR' => ''], 'banner||save' => ['en_GB' => '@me', 'fr_FR' => ''], 'banner||widget' => ['en_GB' => "\tTab", 'fr_FR' => '']];
        $slots = FakeSlots::make($values, ['banner||title' => 'banner', 'banner||body' => 'banner', 'banner||save' => 'banner', 'banner||widget' => 'banner']);
        $csv = TranslationCsv::export($slots, 'fr_FR', 'en_GB');

        $this->assertStringContainsString('"banner","\'quoted"', $csv);
        $this->assertStringContainsString('"banner","\'-1 off"', $csv);
        $this->assertStringContainsString('"banner","\'@me"', $csv);
        $this->assertStringContainsString("\"banner\",\"'\tTab\"", $csv);
    }

    public function testExtraCellsAreIgnored(): void
    {
        $rows = TranslationCsv::parse($this->body('fr_FR', ["\"a\",\"b\",\"c\",\"d\",\"extra\"\r\n"]), 'fr_FR');

        $this->assertSame([['key' => 'a', 'value' => 'd']], $rows);
    }

    public function testAnOverlongCellRejectsTheFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TranslationCsv::parse($this->body('fr_FR', ['"a","b","c","' . str_repeat('x', TranslationCsv::MAX_CELL_BYTES + 1) . '"' . "\r\n"]), 'fr_FR');
    }

    public function testACellAtTheLimitIsAccepted(): void
    {
        $rows = TranslationCsv::parse($this->body('fr_FR', ['"a","b","c","' . str_repeat('x', TranslationCsv::MAX_CELL_BYTES) . '"' . "\r\n"]), 'fr_FR');

        $this->assertCount(1, $rows);
    }

    public function testHeaderWithExtraColumnsIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TranslationCsv::parse('"# locale=fr_FR"' . "\r\n" . '"key","area","default_text","translation","x"' . "\r\n", 'fr_FR');
    }

    public function testLocaleLineWithExtraCellsIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TranslationCsv::parse('"# locale=fr_FR","x"' . "\r\n" . '"key","area","default_text","translation"' . "\r\n", 'fr_FR');
    }
}
