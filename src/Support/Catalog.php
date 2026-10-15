<?php

namespace CookieManagerKit\Support;

use Concrete\Core\Page\Page;
use Concrete\Core\Cache\Page\PageCache;
use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Entity\{BannerContent, Category, Settings};

/**
 * Reads the settings, categories and banner text once, turns them into plain data and keeps that in Concrete's
 * "expensive" cache. Every admin save calls flush(), which also clears the full-page cache because pages embed the
 * snapshot hash. The essential cookie names are NOT part of the snapshot: config() resolves them on every call.
 */
final class Catalog
{
    private const KEY = 'cookie_manager_kit/snapshot/v1';
    private const LIFETIME = 43200;

    /** Built-in English text, used when an admin has not provided a value. */
    public const DEFAULTS = [
        'title' => 'We value your privacy',
        'body' => 'We use cookies to make this site work and, with your permission, to understand how it is used and to show relevant content. You can change your choice at any time.',
        'acceptAll' => 'Accept all',
        'rejectAll' => 'Reject all',
        'customise' => 'Customise',
        'save' => 'Save my choices',
        'widget' => 'Cookie settings',
        'policyLinkText' => 'Cookie policy',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $memo = null;

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $cache = app('cache/expensive');
        $item = $cache->getItem(self::KEY);

        if (!$item->isMiss() && is_array($item->get())) {
            return self::$memo = $item->get();
        }

        $data = self::build();
        $item->set($data);
        $item->expiresAfter(self::LIFETIME);
        $cache->save($item);

        return self::$memo = $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function build(): array
    {
        $em = app(EntityManagerInterface::class);
        $settings = Settings::get();

        $banner = [];
        foreach ($em->getRepository(BannerContent::class)->findAll() as $row) {
            $banner[$row->getLocale()] = [
                'title' => $row->getTitle(),
                'body' => $row->getBody(),
                'acceptAll' => $row->getAcceptAllLabel(),
                'rejectAll' => $row->getRejectAllLabel(),
                'customise' => $row->getCustomiseLabel(),
                'save' => $row->getSaveLabel(),
                'widget' => $row->getWidgetLabel(),
                'policyLinkText' => $row->getPolicyLinkText(),
                'policyUrl' => self::pageUrl($row->getPolicyPageId()),
            ];
        }

        $categories = [];
        foreach ($em->getRepository(Category::class)->findBy([], ['sortOrder' => 'ASC', 'handle' => 'ASC']) as $category) {
            $content = [];
            foreach ($category->getContents() as $row) {
                $content[$row->getLocale()] = ['title' => $row->getTitle(), 'description' => $row->getDescription()];
            }

            $services = [];
            foreach ($category->getServices() as $service) {
                if (!$service->isActive()) {
                    continue;
                }

                $descriptions = [];
                foreach ($service->getContents() as $row) {
                    $descriptions[$row->getLocale()] = $row->getDescription();
                }

                $services[] = ['name' => $service->getName(), 'vendor' => $service->getVendor(), 'content' => $descriptions];
            }

            $categories[] = [
                'handle' => $category->getHandle(),
                'necessary' => $category->isNecessary(),
                'sort' => $category->getSortOrder(),
                'content' => $content,
                'services' => $services,
            ];
        }

        $data = [
            'settings' => [
                'policyVersion' => $settings->getPolicyVersion(),
                'lifetimeDays' => $settings->getLifetimeDays(),
                'widget' => $settings->isWidgetEnabled(),
                'position' => $settings->getPosition(),
                'layout' => $settings->getLayout(),
                'font' => $settings->getFont(),
                'radius' => $settings->getRadius(),
                'shadow' => $settings->hasShadow(),
                'tokens' => $settings->getTokens(),
            ],
            'banner' => $banner,
            'categories' => $categories,
            'defaults' => self::DEFAULTS,
        ];

        $data['hash'] = substr(sha1(Output::json($data)), 0, 12);

        return $data;
    }

    private static function pageUrl(?int $cid): string
    {
        if (!$cid) {
            return '';
        }

        $page = Page::getByID($cid);

        return $page && !$page->isError() ? Output::safeUrl((string) $page->getCollectionLink()) : '';
    }

    public static function hash(): string
    {
        return (string) self::snapshot()['hash'];
    }

    /**
     * @return list<string>
     */
    public static function handles(): array
    {
        return array_values(array_map(static fn(array $c): string => $c['handle'], self::snapshot()['categories']));
    }

    public static function policyVersion(): int
    {
        return (int) self::snapshot()['settings']['policyVersion'];
    }

    public static function lifetimeDays(): int
    {
        return (int) self::snapshot()['settings']['lifetimeDays'];
    }

    public static function defaultLocale(): string
    {
        $locale = app('site')->getSite()->getDefaultLocale();

        return $locale ? $locale->getLocale() : 'en_US';
    }

    /**
     * @return list<string>
     */
    public static function siteLocales(): array
    {
        $locales = [];

        foreach (app('site')->getSite()->getLocales() as $locale) {
            $locales[] = $locale->getLocale();
        }

        return $locales;
    }

    public static function resolveLocale(string $requested): string
    {
        return LocaleResolver::resolve($requested, self::siteLocales(), self::defaultLocale());
    }

    /**
     * @return array<string, mixed>
     */
    public static function config(string $requestedLocale): array
    {
        return ConfigBuilder::build(
            self::snapshot(),
            self::resolveLocale($requestedLocale),
            self::defaultLocale(),
            EssentialCookies::names(),
        );
    }

    /**
     * Call after every admin save.
     */
    public static function flush(): void
    {
        self::$memo = null;
        app('cache/expensive')->getItem(self::KEY)->clear();

        $pageCache = PageCache::getLibrary();

        if ($pageCache) {
            $pageCache->flush();
        }
    }
}
