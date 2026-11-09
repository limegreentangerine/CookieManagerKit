<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Consent\ConsentTrigger;
use CookieManagerKit\Entity\{ConsentLogEntry, ConsentSubject, PolicyVersionChange};

final class ConsentEntitiesTest extends TestCase
{
    public function testTriggerAllowlist(): void
    {
        $this->assertSame(['accept_all', 'reject_all', 'custom', 'gpc', 'reconsent'], ConsentTrigger::ALL);
        $this->assertTrue(ConsentTrigger::isValid('gpc'));
        $this->assertFalse(ConsentTrigger::isValid('changed'));
        $this->assertFalse(ConsentTrigger::isValid('<script>'));
        $this->assertFalse(ConsentTrigger::isValid(['gpc']));
        $this->assertFalse(ConsentTrigger::isValid(null));
    }

    public function testALogEntryStoresCategoriesAsAList(): void
    {
        $entry = (new ConsentLogEntry())
            ->setSubject(new ConsentSubject())
            ->setPolicyVersion(3)
            ->setCategories(['necessary', 'analytics'])
            ->setTrigger('accept_all')
            ->setLocale('en_GB')
            ->setUserAgentFamily('Chrome/desktop')
            ->setCreatedAt(1700000000);

        $this->assertSame(['necessary', 'analytics'], $entry->getCategories());
        $this->assertSame(3, $entry->getPolicyVersion());
        $this->assertSame('accept_all', $entry->getTrigger());
        $this->assertSame('Chrome/desktop', $entry->getUserAgentFamily());
        $this->assertSame(1700000000, $entry->getCreatedAt());
    }

    public function testAnUnknownTriggerBecomesCustom(): void
    {
        $this->assertSame('custom', (new ConsentLogEntry())->setTrigger('"><script>')->getTrigger());
    }

    public function testCorruptStoredCategoriesReadAsEmpty(): void
    {
        $entry = new ConsentLogEntry();
        $property = new \ReflectionProperty(ConsentLogEntry::class, 'categories');
        $property->setValue($entry, '{not json');

        $this->assertSame([], $entry->getCategories());
    }

    public function testAPolicyChangeNeedsAReasonAndCapsItsLength(): void
    {
        $change = (new PolicyVersionChange())->setVersion(2)->setReason(str_repeat('a', 900))->setAdminUserId(1)->setCreatedAt(5);

        $this->assertSame(500, mb_strlen($change->getReason()));
        $this->assertSame(2, $change->getVersion());

        $this->expectException(\InvalidArgumentException::class);
        (new PolicyVersionChange())->setReason("  \n ");
    }

    public function testAReasonIsStoredAsPlainText(): void
    {
        $change = (new PolicyVersionChange())->setReason("Added <b>GA</b>\x00 to analytics");

        $this->assertStringNotContainsString("\x00", $change->getReason());
    }
}
