<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\{Cookie, Service};

final class CookieTest extends TestCase
{
    public function testTheNameIsPlainTextAndCapped(): void
    {
        $cookie = (new Cookie())->setName("  <b>_ga</b>\n" . str_repeat('x', 200));

        $this->assertStringStartsWith('_ga', $cookie->getName());
        $this->assertSame(Cookie::MAX_NAME, mb_strlen($cookie->getName()));
    }

    public function testDurationIsAWholeNumberOfDaysClamped(): void
    {
        $this->assertSame(0, (new Cookie())->getDurationDays());
        $this->assertSame(30, (new Cookie())->setDurationDays('30')->getDurationDays());
        $this->assertSame(0, (new Cookie())->setDurationDays('-5')->getDurationDays());
        $this->assertSame(0, (new Cookie())->setDurationDays('abc')->getDurationDays());
        $this->assertSame(0, (new Cookie())->setDurationDays(2.5)->getDurationDays());
        $this->assertSame(Cookie::MAX_DAYS, (new Cookie())->setDurationDays(999999)->getDurationDays());
    }

    public function testPurposeIsStoredPerLocaleAsPlainText(): void
    {
        $cookie = (new Cookie())->setName('_ga');
        $cookie->setContent('en_GB', "Counts visits <script>x</script>\nsecond line");
        $cookie->setContent('en_GB', 'Counts visits');

        $this->assertCount(1, $cookie->getContents());
        $this->assertSame('Counts visits', $cookie->getContent('en_GB')?->getPurpose());
        $this->assertNull($cookie->getContent('fr_FR'));
    }

    public function testAnInvalidLocaleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Cookie())->setContent('not a locale', 'x');
    }

    public function testAServiceFindsAndDeduplicatesItsCookiesIgnoringCase(): void
    {
        $service = new Service();
        $first = $service->addCookie('_ga');

        $this->assertSame($first, $service->addCookie('_GA'));
        $this->assertSame($first, $service->getCookie('_Ga'));
        $this->assertNull($service->addCookie('   '));
        $this->assertCount(1, $service->getCookies());
        $this->assertSame($service, $first->getService());
    }
}
