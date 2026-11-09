<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\StatsCounter;

final class StatsCounterTest extends TestCase
{
    public function testTableAndTheUniqueKeyTheUpsertDependsOn(): void
    {
        $doc = (string) (new \ReflectionClass(StatsCounter::class))->getDocComment();

        $this->assertStringContainsString('name="cmkStats"', $doc);
        $this->assertStringContainsString('columns={"statDay", "locale", "policyVersion", "metric"}', $doc);
    }

    public function testColumnsMatchTheNamesTheStoreWrites(): void
    {
        $class = new \ReflectionClass(StatsCounter::class);

        foreach (['statDay', 'locale', 'policyVersion', 'metric', 'total'] as $property) {
            $this->assertTrue($class->hasProperty($property), $property);
        }
    }
}
