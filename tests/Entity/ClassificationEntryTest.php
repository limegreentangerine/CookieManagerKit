<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\ClassificationEntry;

final class ClassificationEntryTest extends TestCase
{
    public function testTableName(): void
    {
        $this->assertStringContainsString('name="cmkClassificationEntries"', (string) (new \ReflectionClass(ClassificationEntry::class))->getDocComment());
    }

    public function testDefineStoresCleanValues(): void
    {
        $entry = (new ClassificationEntry())->define('cookie', '_ga', 'analytics', "Goo\nogle", '<i>Distinguishes</i> visitors', '2 years', ClassificationEntry::BUNDLED);

        $this->assertSame('cookie', $entry->getKind());
        $this->assertSame('_ga', $entry->getPattern());
        $this->assertSame('analytics', $entry->getCategoryHandle());
        $this->assertSame('Goo ogle', $entry->getVendor());
        $this->assertSame('Distinguishes visitors', $entry->getDescription());
        $this->assertSame('2 years', $entry->getDuration());
        $this->assertSame(ClassificationEntry::BUNDLED, $entry->getOrigin());
    }

    public function testUnknownKindAndOriginFallBack(): void
    {
        $entry = (new ClassificationEntry())->define('weird', '_x', 'analytics', '', '', '', 'sneaky');

        $this->assertSame(ClassificationEntry::COOKIE, $entry->getKind());
        $this->assertSame(ClassificationEntry::BUNDLED, $entry->getOrigin());
    }
}
