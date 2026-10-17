<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\Service;
use CookieManagerKit\Support\CookieForm;

final class CookieFormTest extends TestCase
{
    private function persist(Service|\CookieManagerKit\Entity\Cookie $entity, int $id): void
    {
        (new \ReflectionProperty(\ClassKit\Entity\Core\BaseEntity::class, 'id'))->setValue($entity, $id);
    }

    private function row(?int $id, string $name): array
    {
        return ['id' => $id, 'name' => $name, 'days' => 0, 'purposes' => ['en_GB' => '']];
    }

    private function twoCookies(): Service
    {
        $service = new Service();
        $this->persist($service->addCookie('A'), 1);
        $this->persist($service->addCookie('B'), 2);

        return $service;
    }

    private function assertUnchanged(Service $service): void
    {
        $this->assertSame(['A', 'B'], array_map(static fn($c) => $c->getName(), $service->getCookies()->toArray()));
    }
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
        $long = CookieForm::parse([['name' => str_repeat('a', 200)]], ['en_GB']);
        $this->assertSame(128, strlen($long[0]['name']));
        $this->assertSame(['en_GB' => 'aok'], $rows[0]['purposes']);
    }

    public function testAtMostMaxRowsAreKept(): void
    {
        $posted = [];
        for ($i = 0; $i < CookieForm::MAX_ROWS + 30; $i++) {
            $posted[] = ['name' => 'c' . $i];
        }

        $this->assertCount(CookieForm::MAX_ROWS, CookieForm::parse($posted, ['en_GB']));
    }

    public function testPostingMoreRowsThanTheCapIsReportedAsExceeding(): void
    {
        $posted = array_fill(0, CookieForm::MAX_ROWS + 1, ['name' => 'x']);

        $this->assertTrue(CookieForm::exceeds($posted));
        $this->assertFalse(CookieForm::exceeds(array_slice($posted, 0, CookieForm::MAX_ROWS)));
        $this->assertFalse(CookieForm::exceeds(null));
    }

    public function testTheFormRendersEveryStoredCookieAndAFewBlankRows(): void
    {
        $this->assertSame(3, CookieForm::formRows(0));
        $this->assertSame(63, CookieForm::formRows(60));
        $this->assertSame(CookieForm::MAX_ROWS, CookieForm::formRows(CookieForm::MAX_ROWS - 1) - 0);
        $this->assertSame(CookieForm::MAX_ROWS, CookieForm::formRows(CookieForm::MAX_ROWS));
        $this->assertSame(CookieForm::MAX_ROWS + 20, CookieForm::formRows(CookieForm::MAX_ROWS + 20));
    }

    public function testSixtyStoredCookiesSurviveASaveOfTheRenderedRows(): void
    {
        $service = new Service();
        $rows = [];
        for ($i = 0; $i < 60; $i++) {
            $this->persist($service->addCookie('c' . $i), $i + 1);
            $rows[] = $this->row($i + 1, 'c' . $i);
        }

        $posted = [];
        foreach ($rows as $row) {
            $posted[] = ['id' => (string) $row['id'], 'name' => $row['name'], 'days' => '0'];
        }

        for ($i = 0; $i < 3; $i++) {
            $posted[] = ['id' => '', 'name' => '', 'days' => '0'];
        }

        CookieForm::apply($service, CookieForm::parse($posted, ['en_GB']));

        $this->assertCount(60, $service->getCookies());
    }

    public function testApplyRefusesAServiceWithMoreCookiesThanTheFormCanCarry(): void
    {
        $service = new Service();
        for ($i = 0; $i < CookieForm::MAX_ROWS + 1; $i++) {
            $service->addCookie('c' . $i);
        }

        $this->assertTrue(CookieForm::overflows($service));

        try {
            CookieForm::apply($service, []);
            $this->fail('apply must refuse');
        } catch (\OverflowException) {
            $this->assertCount(CookieForm::MAX_ROWS + 1, $service->getCookies());
        }
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

    public function testRenameById(): void
    {
        $service = $this->twoCookies();

        CookieForm::apply($service, [$this->row(1, 'C'), $this->row(2, 'B')]);

        $this->assertSame(['C', 'B'], array_map(static fn($c) => $c->getName(), $service->getCookies()->toArray()));
    }

    public function testIdFromAnotherServiceIsIgnored(): void
    {
        $service = $this->twoCookies();

        CookieForm::apply($service, [$this->row(99, 'New'), $this->row(1, 'A'), $this->row(2, 'B')]);

        $this->assertCount(3, $service->getCookies());
        $this->assertNotNull($service->getCookie('New'));
    }

    public function testSwapIsRejectedAndNothingChanges(): void
    {
        $service = $this->twoCookies();

        try {
            CookieForm::apply($service, [$this->row(1, 'B'), $this->row(2, 'A')]);
            $this->fail('Expected an exception');
        } catch (\InvalidArgumentException) {
            $this->assertUnchanged($service);
        }
    }

    public function testRenameAwayPlusNewRowWithOldNameIsRejected(): void
    {
        $service = $this->twoCookies();

        try {
            CookieForm::apply($service, [$this->row(1, 'Y'), $this->row(null, 'A'), $this->row(2, 'B')]);
            $this->fail('Expected an exception');
        } catch (\InvalidArgumentException) {
            $this->assertUnchanged($service);
        }
    }

    public function testTwoRowsForTheSameCookieAreRejected(): void
    {
        $service = $this->twoCookies();

        try {
            CookieForm::apply($service, [$this->row(null, 'A'), $this->row(1, 'A')]);
            $this->fail('Expected an exception');
        } catch (\InvalidArgumentException) {
            $this->assertUnchanged($service);
        }
    }

    public function testRemoveAndReAddReusesTheCookie(): void
    {
        $service = $this->twoCookies();
        $original = $service->getCookie('A');

        CookieForm::apply($service, [$this->row(null, 'A')]);

        $this->assertCount(1, $service->getCookies());
        $this->assertSame($original, $service->getCookie('A'));
    }
}
