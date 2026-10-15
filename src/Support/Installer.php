<?php

namespace CookieManagerKit\Support;

use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Entity\{BannerContent, Category, Settings};

/**
 * Default data created on install and upgrade. Idempotent: it only ever adds what is missing and never overwrites
 * what an admin has edited.
 */
final class Installer
{
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

    public static function seed(EntityManagerInterface $em, string $locale): void
    {
        if ($em->getRepository(Settings::class)->findOneBy([]) === null) {
            $em->persist(new Settings());
        }

        foreach (self::CATEGORIES as $definition) {
            $category = $em->getRepository(Category::class)->findOneBy(['handle' => $definition['handle']]);

            if ($category === null) {
                $category = (new Category())
                    ->setHandle($definition['handle'])
                    ->setSortOrder($definition['sort'])
                    ->setNecessary($definition['necessary']);
                $category->setContent($locale, $definition['title'], $definition['description']);
                $em->persist($category);
            } elseif ($definition['necessary'] && !$category->isNecessary()) {
                $category->setNecessary(true);
            }
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
