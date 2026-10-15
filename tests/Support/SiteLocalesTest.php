<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use CookieManagerKit\Support\SiteLocales;
use PHPUnit\Framework\TestCase;

final class SiteLocalesTest extends TestCase
{
    private const MAP = ['en_GB' => 'English (en_GB)', 'fr_FR' => 'French (fr_FR)', 'de_DE' => 'German (de_DE)'];

    public function testDefaultAlreadyFirstIsUnchanged(): void
    {
        $this->assertSame(['en_GB', 'fr_FR', 'de_DE'], array_keys(SiteLocales::defaultFirst(self::MAP, 'en_GB')));
    }

    public function testDefaultLastMovesFirstKeepingOthersInOrder(): void
    {
        $this->assertSame(['de_DE', 'en_GB', 'fr_FR'], array_keys(SiteLocales::defaultFirst(self::MAP, 'de_DE')));
    }

    public function testDefaultInTheMiddleMovesFirst(): void
    {
        $this->assertSame(['fr_FR', 'en_GB', 'de_DE'], array_keys(SiteLocales::defaultFirst(self::MAP, 'fr_FR')));
    }

    public function testMissingDefaultLeavesMapUnchanged(): void
    {
        $this->assertSame(self::MAP, SiteLocales::defaultFirst(self::MAP, 'es_ES'));
        $this->assertSame(self::MAP, SiteLocales::defaultFirst(self::MAP, ''));
    }

    public function testEmptyMap(): void
    {
        $this->assertSame([], SiteLocales::defaultFirst([], 'en_GB'));
    }

    public function testLabelsArePreserved(): void
    {
        $result = SiteLocales::defaultFirst(self::MAP, 'de_DE');
        $this->assertSame('German (de_DE)', $result['de_DE']);
        $this->assertSame('English (en_GB)', $result['en_GB']);
        $this->assertSame('French (fr_FR)', $result['fr_FR']);
    }
}
