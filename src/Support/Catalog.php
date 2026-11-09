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
    private const KEY = 'cookie_manager_kit/snapshot/v3';
    private const LIFETIME = 43200;

    /** Built-in English text, used when an admin has not provided a value. */
    public const DEFAULTS = [
        'title' => 'This website uses cookies',
        'body' => 'We use cookies to personalise content and ads, to provide social media features and to analyse our traffic. We also share information about your use of our site with our social media, advertising and analytics partners who may combine it with other information that you\'ve provided to them or that they\'ve collected from your use of their services.',
        'acceptAll' => 'Accept all',
        'rejectAll' => 'Reject all',
        'customise' => 'Customise',
        'save' => 'Save my choices',
        'widget' => 'Cookie settings',
        'policyLinkText' => 'Cookie policy',
        'embedMessage' => 'This content is provided by {service} and needs your consent to load.',
        'embedAcceptLabel' => 'Accept {category} cookies',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $memo = null;

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
                'embedMessage' => $row->getEmbedMessage(),
                'embedAcceptLabel' => $row->getEmbedAcceptLabel(),
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

                $cookies = [];

                foreach ($service->getCookies() as $cookie) {
                    $purposes = [];

                    foreach ($cookie->getContents() as $row) {
                        $purposes[$row->getLocale()] = $row->getPurpose();
                    }

                    $cookies[] = ['name' => $cookie->getName(), 'days' => $cookie->getDurationDays(), 'content' => $purposes];
                }

                $services[] = [
                    'name' => $service->getName(),
                    'vendor' => $service->getVendor(),
                    'content' => $descriptions,
                    'domains' => $service->getDomains(),
                    'cookies' => $cookies,
                ];
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
                'consentMode' => $settings->isConsentModeEnabled(),
                'defaultModel' => $settings->getDefaultModel(),
                'widget' => $settings->isWidgetEnabled(),
                'position' => $settings->getPosition(),
                'layout' => $settings->getLayout(),
                'font' => $settings->getFont(),
                'radius' => $settings->getRadius(),
                'shadow' => $settings->hasShadow(),
                'iconUrl' => BannerIcon::url($settings->getIconFileId()),
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

    public static function consentMode(): bool
    {
        return (bool) (self::snapshot()['settings']['consentMode'] ?? false);
    }

    public static function defaultModel(): string
    {
        $model = self::snapshot()['settings']['defaultModel'] ?? 'opt_in';

        return $model === 'opt_out' ? 'opt_out' : 'opt_in';
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
            EssentialCookies::describe(EssentialCookies::names(), self::lifetimeDays()),
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
