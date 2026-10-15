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

    private function describeWith(array $names, int $days = 180, mixed $session = 'CONCRETE', mixed $remember = null): array
    {
        return EssentialCookies::describe($names, $days, static fn(string $key): mixed => match ($key) {
            'concrete.session.name' => $session,
            'concrete.session.remember_me.lifetime' => $remember,
            default => null,
        });
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

    public function testDescribeGivesPurposeAndDurationForEveryKnownCookieInOrder(): void
    {
        $names = ['MYSITE', 'MYSITE_LOGIN', 'ccmAuthUserHash', 'ConcreteSitemapTreeID', 'ConcreteSitemapTreeID-2', 'ConcreteSitemap-expand', 'dashboardPanelStatus', 'cmk_consent'];
        $rows = $this->describeWith($names, 180, 'MYSITE');

        $this->assertSame($names, array_column($rows, 'name'));
        $this->assertSame(['Keeps you signed in and your session working.', 'Session'], [$rows[0]['purpose'], $rows[0]['duration']]);
        $this->assertSame(['Remembers that you signed in on this browser.', '2 weeks'], [$rows[1]['purpose'], $rows[1]['duration']]);
        $this->assertSame(["Keeps you signed in if you chose 'stay signed in'.", '2 weeks'], [$rows[2]['purpose'], $rows[2]['duration']]);
        $this->assertSame(['Remembers which site tree you were browsing in the dashboard.', 'Session'], [$rows[3]['purpose'], $rows[3]['duration']]);
        $this->assertSame($rows[3]['purpose'], $rows[4]['purpose']);
        $this->assertSame(['Remembers which sitemap branches are open in the dashboard.', 'Session'], [$rows[5]['purpose'], $rows[5]['duration']]);
        $this->assertSame(['Remembers whether the dashboard panel is open.', 'Session'], [$rows[6]['purpose'], $rows[6]['duration']]);
        $this->assertSame(['Remembers your cookie choices.', '180 days'], [$rows[7]['purpose'], $rows[7]['duration']]);
    }

    public function testDescribeLeavesUnknownCookiesBlank(): void
    {
        $this->assertSame([['name' => 'mystery', 'purpose' => '', 'duration' => '']], $this->describeWith(['mystery']));
        // The _LOGIN of a name that is not the session is not claimed.
        $this->assertSame('', $this->describeWith(['OTHER_LOGIN'])[0]['purpose']);
    }

    public function testDescribeFormatsTheRememberMeLifetime(): void
    {
        $duration = fn(mixed $seconds): string => $this->describeWith(['ccmAuthUserHash'], 180, 'CONCRETE', $seconds)[0]['duration'];

        $this->assertSame('2 weeks', $duration(null));
        $this->assertSame('2 weeks', $duration('nonsense'));
        $this->assertSame('2 weeks', $duration(-5));
        $this->assertSame('30 days', $duration(30 * 86400));
        $this->assertSame('1 week', $duration(7 * 86400));
        $this->assertSame('1 day', $duration(86400));
        $this->assertSame('90 days', $duration('7776000'));
    }

    public function testTheSessionLoginCookieLastsAsLongAsTheRememberMeLifetime(): void
    {
        $this->assertSame('30 days', $this->describeWith(['MYSITE_LOGIN'], 180, 'MYSITE', 30 * 86400)[0]['duration']);
        $this->assertSame('Session', $this->describeWith(['MYSITE'], 180, 'MYSITE', 30 * 86400)[0]['duration']);
    }

    public function testDescribeSingularDayForConsentLifetime(): void
    {
        $this->assertSame('1 day', $this->describeWith(['cmk_consent'], 1)[0]['duration']);
    }
}
