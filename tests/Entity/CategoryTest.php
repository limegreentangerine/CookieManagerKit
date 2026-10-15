<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\{Category, Service};

final class CategoryTest extends TestCase
{
    public function testHandleMustBeAValidSlug(): void
    {
        $category = (new Category())->setHandle('analytics');
        $this->assertSame('analytics', $category->getHandle());

        foreach (['', 'Upper', '<script>', "a\n", str_repeat('a', 33)] as $bad) {
            try {
                (new Category())->setHandle($bad);
                $this->fail('Expected an exception for ' . var_export($bad, true));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testHandleCannotBeChangedOnceSet(): void
    {
        $this->expectException(\LogicException::class);
        (new Category())->setHandle('analytics')->setHandle('marketing');
    }

    public function testContentIsPerLocaleAndPlainText(): void
    {
        $category = (new Category())->setHandle('analytics');
        $category->setContent('en_GB', '<b>Analytics</b>', "Helps us <script>x</script>improve\n\n\n\nthe site");
        $category->setContent('cy_GB', 'Dadansoddeg', '');

        $this->assertSame('Analytics', $category->getContent('en_GB')?->getTitle());
        $this->assertStringNotContainsString('<', (string) $category->getContent('en_GB')?->getDescription());
        $this->assertSame('Dadansoddeg', $category->getContent('cy_GB')?->getTitle());
        $this->assertNull($category->getContent('fr_FR'));
        $this->assertCount(2, $category->getContents());
    }

    public function testSettingContentTwiceUpdatesTheSameRow(): void
    {
        $category = (new Category())->setHandle('analytics');
        $category->setContent('en_GB', 'One', '');
        $category->setContent('en_GB', 'Two', '');

        $this->assertCount(1, $category->getContents());
        $this->assertSame('Two', $category->getContent('en_GB')?->getTitle());
    }

    public function testInvalidLocaleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Category())->setHandle('analytics')->setContent('<script>', 'x', 'y');
    }

    public function testNecessaryCategoryCanNeverBeDeleted(): void
    {
        $necessary = (new Category())->setHandle('necessary')->setNecessary(true);
        $this->assertTrue($necessary->isNecessary());
        $this->assertFalse($necessary->canBeDeleted());
    }

    public function testCustomCategoryCanBeDeletedOnlyWhenEmpty(): void
    {
        $category = (new Category())->setHandle('custom');
        $this->assertTrue($category->canBeDeleted());

        $service = (new Service())->setName('Tool')->setCategory($category);
        $category->getServices()->add($service);

        $this->assertFalse($category->canBeDeleted());
    }
}
