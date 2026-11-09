<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Translation;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Translation\TranslationReport;

final class TranslationReportTest extends TestCase
{
    public function testMatrixCountsOnlyFieldsThatHaveDefaultText(): void
    {
        $values = [
            'banner||title' => ['en_GB' => 'Hello', 'fr_FR' => 'Bonjour'],
            'banner||body' => ['en_GB' => 'Body'],
            'banner||save' => ['en_GB' => ''],
            'cookie|1|purpose' => ['en_GB' => 'P', 'fr_FR' => ''],
        ];
        $slots = FakeSlots::make($values, ['banner||title' => 'banner', 'banner||body' => 'banner', 'banner||save' => 'banner', 'cookie|1|purpose' => 'cookie']);

        $matrix = TranslationReport::matrix($slots, ['en_GB', 'fr_FR'], 'en_GB');

        $this->assertSame(['filled' => 2, 'total' => 2], $matrix['banner']['en_GB']);
        $this->assertSame(['filled' => 1, 'total' => 2], $matrix['banner']['fr_FR']);
        $this->assertSame(['filled' => 0, 'total' => 1], $matrix['cookie']['fr_FR']);
        $this->assertSame(['filled' => 0, 'total' => 0], $matrix['service']['fr_FR']);
    }

    public function testMissingListsEmptyTargetFieldsThatHaveDefaultText(): void
    {
        $values = ['a' => ['en_GB' => 'A', 'fr_FR' => ''], 'b' => ['en_GB' => 'B', 'fr_FR' => 'B!'], 'c' => ['en_GB' => '']];
        $slots = FakeSlots::make($values, ['a' => 'banner', 'b' => 'banner', 'c' => 'banner']);

        $this->assertSame(['a'], array_map(static fn($s) => $s->key, TranslationReport::missing($slots, 'fr_FR', 'en_GB')));
    }

    public function testCopyFromDefaultFillsOnlyEmptyFieldsOfTheChosenAreaAndNeverOverwrites(): void
    {
        $values = ['a' => ['en_GB' => 'A', 'fr_FR' => ''], 'b' => ['en_GB' => 'B', 'fr_FR' => 'Mine'], 'c' => ['en_GB' => 'C']];
        $slots = FakeSlots::make($values, ['a' => 'banner', 'b' => 'banner', 'c' => 'service']);

        $count = TranslationReport::copyFromDefault($slots, 'banner', 'fr_FR', 'en_GB');

        $this->assertSame(1, $count);
        $this->assertSame('A', $values['a']['fr_FR']);
        $this->assertSame('Mine', $values['b']['fr_FR']);
        $this->assertArrayNotHasKey('fr_FR', $values['c']);
    }

    public function testCopyIntoTheDefaultLocaleDoesNothing(): void
    {
        $values = ['a' => ['en_GB' => '']];
        $slots = FakeSlots::make($values, ['a' => 'banner']);

        $this->assertSame(0, TranslationReport::copyFromDefault($slots, 'banner', 'en_GB', 'en_GB'));
    }

    public function testAnUnknownAreaCopiesNothing(): void
    {
        $values = ['a' => ['en_GB' => 'A']];
        $slots = FakeSlots::make($values, ['a' => 'banner']);

        $this->assertSame(0, TranslationReport::copyFromDefault($slots, 'nonsense', 'fr_FR', 'en_GB'));
        $this->assertArrayNotHasKey('fr_FR', $values['a']);
    }
}
