<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Support;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Support\{Installer, Text};

final class InstallerTest extends TestCase
{
    /**
     * @param list<string> $existing
     *
     * @return list<string>
     */
    private function missing(array $existing): array
    {
        return array_column(Installer::missingCategories($existing), 'handle');
    }
    public function testDefaultCategoriesAreValid(): void
    {
        $handles = array_column(Installer::CATEGORIES, 'handle');

        $this->assertSame(['necessary', 'preferences', 'analytics', 'marketing'], $handles);
        $this->assertSame($handles, array_values(array_unique($handles)));

        foreach (Installer::CATEGORIES as $category) {
            $this->assertTrue(Text::isHandle($category['handle']));
            $this->assertNotSame('', $category['title']);
            $this->assertNotSame('', $category['description']);
            $this->assertSame($category['title'], strip_tags($category['title']));
            $this->assertSame($category['description'], strip_tags($category['description']));
        }
    }

    public function testOnlyNecessaryIsNecessaryAndItComesFirst(): void
    {
        $necessary = array_filter(Installer::CATEGORIES, static fn(array $c): bool => $c['necessary']);

        $this->assertCount(1, $necessary);
        $this->assertSame('necessary', Installer::CATEGORIES[0]['handle']);
        $this->assertSame(0, Installer::CATEGORIES[0]['sort']);

        $sorts = array_column(Installer::CATEGORIES, 'sort');
        $sorted = $sorts;
        sort($sorted);
        $this->assertSame($sorted, $sorts);
    }

    public function testAFirstInstallCreatesEveryDefault(): void
    {
        $this->assertSame(['necessary', 'preferences', 'analytics', 'marketing'], $this->missing([]));
    }

    public function testAnUpgradeNeverRecreatesDeletedDefaults(): void
    {
        $this->assertSame([], $this->missing(['necessary', 'custom']));
        $this->assertSame([], $this->missing(['necessary', 'analytics']));
        $this->assertSame([], $this->missing(['necessary']));
    }

    public function testNecessaryIsRecreatedWhenMissingFromANonEmptyTable(): void
    {
        $this->assertSame(['necessary'], $this->missing(['custom']));
        $this->assertSame(['necessary'], $this->missing(['preferences', 'analytics', 'marketing']));
    }

    public function testNothingIsReportedForAFullSet(): void
    {
        $this->assertSame([], $this->missing(['necessary', 'preferences', 'analytics', 'marketing']));
    }

    public function testSeedRecordsTheInitialPolicyVersionOnce(): void
    {
        $this->assertSame('Initial version', Installer::INITIAL_REASON);
    }
}
