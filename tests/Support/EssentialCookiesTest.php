<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\EssentialCookies;

final class EssentialCookiesTest extends TestCase
{
    private function reader(?string &$sessionName): callable
    {
        return static function (string $key) use (&$sessionName): mixed {
            return $key === 'concrete.session.name' ? $sessionName : null;
        };
    }

    public function testDefaultSessionNameIsUsedWhenConfigIsMissingOrInvalid(): void
    {
        $this->assertSame(['CONCRETE'], EssentialCookies::sessionNames(null));
        $this->assertSame(['CONCRETE'], EssentialCookies::sessionNames(''));
        $this->assertSame(['CONCRETE'], EssentialCookies::sessionNames(['x']));
        $this->assertSame(['CONCRETE'], EssentialCookies::sessionNames('has space'));
        $this->assertSame(['CONCRETE'], EssentialCookies::sessionNames(str_repeat('a', 65)));
    }

    public function testConfiguredAndActiveNamesAreBothProtectedWhenTheyDiffer(): void
    {
        $this->assertSame(['SITE'], EssentialCookies::sessionNames('SITE', 'SITE'));
        $this->assertSame(['SITE', 'OTHER'], EssentialCookies::sessionNames('SITE', 'OTHER'));
        $this->assertSame(['OTHER'], EssentialCookies::sessionNames('bad name', 'OTHER'));
    }

    public function testNamesIncludeEverythingConcreteNeeds(): void
    {
        $name = 'CONCRETE';
        $names = EssentialCookies::names($this->reader($name));

        foreach (['CONCRETE', 'CONCRETE_LOGIN', 'ccmAuthUserHash', 'ConcreteSitemapTreeID', 'ConcreteSitemap-expand', 'dashboardPanelStatus', 'cmk_consent'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function testAChangeOfSessionNameBetweenCallsIsPickedUpNothingIsCached(): void
    {
        $name = 'CONCRETE';
        $read = $this->reader($name);

        $this->assertTrue(EssentialCookies::isEssential('CONCRETE', $read));
        $this->assertFalse(EssentialCookies::isEssential('MYSITE', $read));

        $name = 'MYSITE'; // another package changed concrete.session.name at runtime

        $this->assertTrue(EssentialCookies::isEssential('MYSITE', $read));
        $this->assertTrue(EssentialCookies::isEssential('MYSITE_LOGIN', $read));
        $this->assertFalse(EssentialCookies::isEssential('CONCRETE', $read));
    }

    public function testSitemapTreeCookieWithNumericSuffixIsEssential(): void
    {
        $name = 'CONCRETE';
        $read = $this->reader($name);

        $this->assertTrue(EssentialCookies::isEssential('ConcreteSitemapTreeID-2', $read));
        $this->assertFalse(EssentialCookies::isEssential('ConcreteSitemapTreeID-x', $read));
        $this->assertFalse(EssentialCookies::isEssential("ConcreteSitemapTreeID-2\n", $read));
    }

    public function testThirdPartyCookiesAreNotEssential(): void
    {
        $name = 'CONCRETE';
        $read = $this->reader($name);

        foreach (['_ga', '_fbp', 'IDE', 'concrete', 'CONCRETE2'] as $cookie) {
            $this->assertFalse(EssentialCookies::isEssential($cookie, $read), $cookie);
        }
    }
}
