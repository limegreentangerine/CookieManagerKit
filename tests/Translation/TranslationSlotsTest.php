<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Translation;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\Cookie;
use CookieManagerKit\Entity\Service;
use CookieManagerKit\Entity\Category;
use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Entity\BannerContent;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\MockObject\MockObject;
use CookieManagerKit\Translation\TranslationSlots;

final class TranslationSlotsTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    /**
     * @param list<object> $banners
     * @param list<object> $categories
     * @param list<object> $services
     */
    private function em(array $banners, array $categories, array $services): EntityManagerInterface&MockObject
    {
        $rows = [BannerContent::class => $banners, Category::class => $categories, Service::class => $services];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(function (string $class) use ($rows): ObjectRepository {
            $repository = $this->createMock(ObjectRepository::class);
            $repository->method('findBy')->willReturn($rows[$class] ?? []);
            $repository->method('findAll')->willReturn($rows[$class] ?? []);

            return $repository;
        });
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        return $em;
    }

    private static function withId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }

    /**
     * @return array{Category, Service, Cookie}
     */
    private function fixtures(): array
    {
        $category = (new Category())->setHandle('analytics');
        $service = (new Service())->setName('Matomo')->setCategory($category);
        self::withId($service, 7);
        $cookie = (new Cookie())->setName('_pk')->setService($service);
        self::withId($cookie, 9);
        $service->getCookies()->add($cookie);

        return [$category, $service, $cookie];
    }

    /**
     * @param list<\CookieManagerKit\Translation\Slot> $slots
     */
    private static function slot(array $slots, string $key): \CookieManagerKit\Translation\Slot
    {
        foreach ($slots as $slot) {
            if ($slot->key === $key) {
                return $slot;
            }
        }

        throw new \OutOfBoundsException($key);
    }

    public function testSlotsExistForEveryTranslatableField(): void
    {
        [$category, $service] = $this->fixtures();
        $banner = (new BannerContent())->setLocale('en_GB');

        $slots = TranslationSlots::collect($this->em([$banner], [$category], [$service]));
        $keys = array_map(static fn($s) => $s->key, $slots);

        $this->assertContains('banner||title', $keys);
        $this->assertContains('banner||embedAcceptLabel', $keys);
        $this->assertContains('category|analytics|title', $keys);
        $this->assertContains('category|analytics|description', $keys);
        $this->assertContains('service|7|description', $keys);
        $this->assertContains('cookie|9|purpose', $keys);
        $this->assertCount(10 + 2 + 1 + 1, $keys);
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    public function testWritingCreatesTheMissingLocaleRowThroughTheEntitySetters(): void
    {
        [$category, $service] = $this->fixtures();
        $slots = TranslationSlots::collect($this->em([(new BannerContent())->setLocale('en_GB')], [$category], [$service]));

        foreach (['banner||title', 'category|analytics|title', 'service|7|description', 'cookie|9|purpose'] as $key) {
            $slot = self::slot($slots, $key);
            $this->assertSame('', $slot->read('fr_FR'));
            $slot->write('fr_FR', '<b>Bonjour</b>');
            $this->assertSame('Bonjour', $slot->read('fr_FR'), $key);
        }

        $this->assertInstanceOf(BannerContent::class, $this->persisted[0]);
        $this->assertCount(4, $this->persisted);
    }

    public function testCategoryTitleAndDescriptionWritesDoNotEraseEachOther(): void
    {
        [$category, $service] = $this->fixtures();
        $slots = TranslationSlots::collect($this->em([], [$category], [$service]));

        self::slot($slots, 'category|analytics|title')->write('fr_FR', 'Titre');
        self::slot($slots, 'category|analytics|description')->write('fr_FR', 'Description');

        $this->assertSame('Titre', self::slot($slots, 'category|analytics|title')->read('fr_FR'));
        $this->assertSame('Description', self::slot($slots, 'category|analytics|description')->read('fr_FR'));
    }
}
