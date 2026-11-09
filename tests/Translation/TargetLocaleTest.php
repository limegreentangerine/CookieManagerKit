<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Translation;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Translation\TargetLocale;

final class TargetLocaleTest extends TestCase
{
    private const OPTIONS = ['en_GB' => 'English', 'fr_FR' => 'French'];

    public function testASiteLocaleOtherThanTheDefaultIsAccepted(): void
    {
        $this->assertSame('fr_FR', TargetLocale::resolve('fr_FR', self::OPTIONS, 'en_GB'));
    }

    public function testTheDefaultAnUnknownLocaleAndNonStringsAreRejected(): void
    {
        $this->assertNull(TargetLocale::resolve('en_GB', self::OPTIONS, 'en_GB'));
        $this->assertNull(TargetLocale::resolve('de_DE', self::OPTIONS, 'en_GB'));
        $this->assertNull(TargetLocale::resolve(['fr_FR'], self::OPTIONS, 'en_GB'));
        $this->assertNull(TargetLocale::resolve(null, self::OPTIONS, 'en_GB'));
        $this->assertNull(TargetLocale::resolve("fr_FR\r\nX: y", self::OPTIONS, 'en_GB'));
    }
}
