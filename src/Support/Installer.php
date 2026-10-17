<?php

namespace CookieManagerKit\Support;

use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Entity\{BannerContent, Category, PolicyVersionChange, Settings};

/**
 * Default data created on install and upgrade. Idempotent: it only ever adds what is missing and never overwrites
 * what an admin has edited.
 */
final class Installer
{
    public const INITIAL_REASON = 'Initial version';

    public const CATEGORIES = [
        [
            'handle' => 'necessary',
            'sort' => 0,
            'necessary' => true,
            'title' => 'Strictly necessary',
            'description' => 'These are needed for the site to work, for example to keep you signed in and to remember your choice here. They cannot be switched off.',
        ],
        [
            'handle' => 'preferences',
            'sort' => 10,
            'necessary' => false,
            'title' => 'Preferences',
            'description' => 'These remember choices you make, such as your language or region.',
        ],
        [
            'handle' => 'analytics',
            'sort' => 20,
            'necessary' => false,
            'title' => 'Analytics',
            'description' => 'These help us understand how visitors use the site so we can improve it.',
        ],
        [
            'handle' => 'marketing',
            'sort' => 30,
            'necessary' => false,
            'title' => 'Marketing',
            'description' => 'These are used to show you relevant content and to measure how well it works.',
        ],
    ];

    /**
     * The definitions to create. `necessary` is always required; the optional defaults are offered only on a first
     * install (no categories at all), so an admin who deleted one never gets it back on upgrade.
     *
     * @param list<string> $existingHandles
     *
     * @return list<array{handle: string, sort: int, necessary: bool, title: string, description: string}>
     */
    public static function missingCategories(array $existingHandles): array
    {
        $firstInstall = $existingHandles === [];

        return array_values(array_filter(
            self::CATEGORIES,
            static fn(array $definition): bool => !in_array($definition['handle'], $existingHandles, true)
                && ($definition['necessary'] || $firstInstall),
        ));
    }

    /**
     * The history row that records the version a site starts on, or null when history already exists.
     */
    public static function initialPolicyChange(?PolicyVersionChange $existing, int $version, int $now): ?PolicyVersionChange
    {
        if ($existing !== null) {
            return null;
        }

        return (new PolicyVersionChange())->setVersion($version)->setReason(self::INITIAL_REASON)->setCreatedAt($now);
    }

    public static function seed(EntityManagerInterface $em, string $locale): void
    {
        if ($em->getRepository(Settings::class)->findOneBy([]) === null) {
            $em->persist(new Settings());
        }

        // Settings::get() reads the row, so it has to be written before the first history row can name its version.
        $em->flush();

        $initial = self::initialPolicyChange(
            $em->getRepository(PolicyVersionChange::class)->findOneBy([]),
            Settings::get()->getPolicyVersion(),
            time(),
        );

        if ($initial !== null) {
            $em->persist($initial);
        }

        $existing = [];

        foreach ($em->getRepository(Category::class)->findAll() as $category) {
            $existing[] = $category->getHandle();

            if ($category->getHandle() === 'necessary' && !$category->isNecessary()) {
                $category->setNecessary(true);
            }
        }

        foreach (self::missingCategories($existing) as $definition) {
            $category = (new Category())
                ->setHandle($definition['handle'])
                ->setSortOrder($definition['sort'])
                ->setNecessary($definition['necessary']);
            $category->setContent($locale, $definition['title'], $definition['description']);
            $em->persist($category);
        }

        if ($em->getRepository(BannerContent::class)->findOneBy(['locale' => $locale]) === null) {
            $defaults = Catalog::DEFAULTS;

            $em->persist(
                (new BannerContent())
                    ->setLocale($locale)
                    ->setTitle($defaults['title'])
                    ->setBody($defaults['body'])
                    ->setAcceptAllLabel($defaults['acceptAll'])
                    ->setRejectAllLabel($defaults['rejectAll'])
                    ->setCustomiseLabel($defaults['customise'])
                    ->setSaveLabel($defaults['save'])
                    ->setWidgetLabel($defaults['widget'])
                    ->setPolicyLinkText($defaults['policyLinkText']),
            );
        }

        $em->flush();
        Catalog::flush();
    }
}
