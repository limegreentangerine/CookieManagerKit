<?php

namespace CookieManagerKit\Translation;

use CookieManagerKit\Support\Text;
use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Entity\{BannerContent, Category, Service};

/**
 * Every translatable field in the package as a Slot, so the translation screen and report can treat them uniformly.
 *
 * Writing a value that sanitises to nothing never creates a new content row (an existing row is updated as normal).
 * Callers that import data, such as the CSV import, must still skip blank cells themselves. Services and cookies
 * without an ID are not addressable and get no slots.
 */
final class TranslationSlots
{
    private const BANNER = [
        'title' => ['Heading', 'getTitle', 'setTitle'],
        'body' => ['Message', 'getBody', 'setBody'],
        'acceptAll' => ['Accept all button', 'getAcceptAllLabel', 'setAcceptAllLabel'],
        'rejectAll' => ['Reject all button', 'getRejectAllLabel', 'setRejectAllLabel'],
        'customise' => ['Customise button', 'getCustomiseLabel', 'setCustomiseLabel'],
        'save' => ['Save button', 'getSaveLabel', 'setSaveLabel'],
        'widget' => ['Settings button', 'getWidgetLabel', 'setWidgetLabel'],
        'policyLinkText' => ['Policy link text', 'getPolicyLinkText', 'setPolicyLinkText'],
        'embedMessage' => ['Blocked embed message', 'getEmbedMessage', 'setEmbedMessage'],
        'embedAcceptLabel' => ['Blocked embed button', 'getEmbedAcceptLabel', 'setEmbedAcceptLabel'],
    ];

    private static function saved(object $entity): bool
    {
        return (new \ReflectionProperty($entity, 'id'))->isInitialized($entity);
    }

    /**
     * @return list<Slot>
     */
    public static function collect(EntityManagerInterface $em): array
    {
        $slots = [];
        /** @var array<string, BannerContent> $rows */
        $rows = [];

        foreach ($em->getRepository(BannerContent::class)->findBy([]) as $row) {
            $rows[$row->getLocale()] = $row;
        }

        foreach (self::BANNER as $field => [$label, $getter, $setter]) {
            $slots[] = new Slot(
                'banner',
                'banner||' . $field,
                $label,
                static function (string $locale) use (&$rows, $getter): string {
                    return isset($rows[$locale]) ? (string) $rows[$locale]->$getter() : '';
                },
                static function (string $locale, string $value) use (&$rows, $em, $setter): void {
                    if (!isset($rows[$locale]) && Text::plain($value) === '') {
                        return;
                    }

                    $row = $rows[$locale] ??= (new BannerContent())->setLocale($locale);
                    $row->$setter($value);
                    $em->persist($row);
                },
            );
        }

        /** @var list<Category> $categories */
        $categories = $em->getRepository(Category::class)->findBy([], ['sortOrder' => 'ASC', 'handle' => 'ASC']);

        foreach ($categories as $category) {
            $prefix = 'category|' . $category->getHandle() . '|';
            $name = $category->getHandle();

            $slots[] = new Slot(
                'category',
                $prefix . 'title',
                'Category: ' . $name . ' title',
                static fn(string $locale): string => $category->getContent($locale)?->getTitle() ?? '',
                static function (string $locale, string $value) use ($category, $em): void {
                    if ($category->getContent($locale) === null && Text::plain($value) === '') {
                        return;
                    }

                    $em->persist($category->setContent($locale, $value, $category->getContent($locale)?->getDescription() ?? ''));
                },
            );
            $slots[] = new Slot(
                'category',
                $prefix . 'description',
                'Category: ' . $name . ' description',
                static fn(string $locale): string => $category->getContent($locale)?->getDescription() ?? '',
                static function (string $locale, string $value) use ($category, $em): void {
                    if ($category->getContent($locale) === null && Text::plain($value) === '') {
                        return;
                    }

                    $em->persist($category->setContent($locale, $category->getContent($locale)?->getTitle() ?? '', $value));
                },
            );
        }

        /** @var list<Service> $services */
        $services = $em->getRepository(Service::class)->findBy([], ['name' => 'ASC']);

        foreach ($services as $service) {
            if (!self::saved($service)) {
                continue;
            }

            $slots[] = new Slot(
                'service',
                'service|' . $service->getID() . '|description',
                'Service: ' . $service->getName(),
                static fn(string $locale): string => $service->getContent($locale)?->getDescription() ?? '',
                static function (string $locale, string $value) use ($service, $em): void {
                    if ($service->getContent($locale) === null && Text::plain($value) === '') {
                        return;
                    }

                    $em->persist($service->setContent($locale, $value));
                },
            );

            foreach ($service->getCookies() as $cookie) {
                if (!self::saved($cookie)) {
                    continue;
                }

                $slots[] = new Slot(
                    'cookie',
                    'cookie|' . $cookie->getID() . '|purpose',
                    'Cookie: ' . $cookie->getName() . ' (' . $service->getName() . ')',
                    static fn(string $locale): string => $cookie->getContent($locale)?->getPurpose() ?? '',
                    static function (string $locale, string $value) use ($cookie, $em): void {
                        if ($cookie->getContent($locale) === null && Text::plain($value) === '') {
                            return;
                        }

                        $em->persist($cookie->setContent($locale, $value));
                    },
                );
            }
        }

        return $slots;
    }
}
