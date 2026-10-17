<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\Service;
use CookieManagerKit\Support\CookieForm;

final class CookieFormTest extends TestCase
{
    public function testBlankNamesAndDuplicatesAreDropped(): void
    {
        $rows = CookieForm::parse([
            ['name' => '_ga', 'days' => '730', 'purpose' => ['en_GB' => 'Counts visits']],
            ['name' => '', 'days' => '1'],
            ['name' => '_GA', 'days' => '5'],
            'junk',
        ], ['en_GB', 'fr_FR']);

        $this->assertCount(1, $rows);
        $this->assertSame('_ga', $rows[0]['name']);
        $this->assertSame(730, $rows[0]['days']);
        $this->assertSame(['en_GB' => 'Counts visits', 'fr_FR' => ''], $rows[0]['purposes']);
    }

    public function testHostileValuesAreNormalised(): void
    {
        $rows = CookieForm::parse([['id' => '12abc', 'name' => '<b>x</b>', 'days' => '-4', 'purpose' => ['en_GB' => '<script>a</script>ok', 'xx' => 'ignored']]], ['en_GB']);

        $this->assertNull($rows[0]['id']);
        $this->assertSame('x', $rows[0]['name']);
        $this->assertSame(0, $rows[0]['days']);
        $this->assertSame(['en_GB' => 'aok'], $rows[0]['purposes']);
    }

    public function testAtMostFiftyRowsAreKept(): void
    {
        $posted = [];
        for ($i = 0; $i < 80; $i++) {
            $posted[] = ['name' => 'c' . $i];
        }

        $this->assertCount(CookieForm::MAX_ROWS, CookieForm::parse($posted, ['en_GB']));
    }

    public function testApplyUpdatesAddsAndRemoves(): void
    {
        $service = new Service();
        $service->addCookie('keep')->setDurationDays(1);
        $service->addCookie('drop');

        CookieForm::apply($service, [
            ['id' => null, 'name' => 'keep', 'days' => 30, 'purposes' => ['en_GB' => 'Kept', 'fr_FR' => '']],
            ['id' => null, 'name' => 'new', 'days' => 0, 'purposes' => ['en_GB' => '']],
        ]);

        $keep = $service->getCookie('keep');
        $new = $service->getCookie('new');

        $this->assertNull($service->getCookie('drop'));
        $this->assertNotNull($keep);
        $this->assertSame(30, $keep->getDurationDays());
        $this->assertSame('Kept', $keep->getContent('en_GB')?->getPurpose());
        $this->assertNull($keep->getContent('fr_FR'));
        $this->assertNotNull($new);
        $this->assertNull($new->getContent('en_GB'));
        $this->assertCount(2, $service->getCookies());
    }
}
