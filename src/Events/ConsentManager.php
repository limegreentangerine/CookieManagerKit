<?php

namespace CookieManagerKit\Events;

use Concrete\Core\Page\Page;
use Concrete\Core\Asset\AssetList;
use Concrete\Core\Support\Facade\Url;
use Concrete\Core\Localization\Localization;
use CookieManagerKit\Support\{Catalog, LoaderScript, TrackingCode};

/**
 * Front end entry point. The output depends only on the page and the admin's settings, never on the visitor, so
 * Concrete's full-page cache stays valid: an inline loader in <head>, two registered assets, and the site's SEO
 * tracking snippets turned inert until the visitor consents to analytics.
 */
class ConsentManager
{
    private const PACKAGE = 'cookie_manager_kit';
    private const EXCLUDED_PATHS = ['/login'];
    private const TRACKING_CATEGORY = 'analytics';

    private static bool $outputHooked = false;

    /**
     * A same-origin path, so the page works whichever host serves it (www or not) and whether or not pretty URLs
     * are on.
     */
    private static function path(string $route): string
    {
        $path = parse_url((string) Url::to($route), PHP_URL_PATH);

        return is_string($path) && str_starts_with($path, '/') ? $path : $route;
    }

    /**
     * Sites with a Content-Security-Policy bind `cookie_manager_kit.csp_nonce` (a string, or a Closure returning
     * one) so the inline loader carries the request's nonce.
     */
    private static function nonce(): ?string
    {
        try {
            $app = app();

            if (!$app->bound('cookie_manager_kit.csp_nonce')) {
                return null;
            }

            return self::nonceFrom($app->make('cookie_manager_kit.csp_nonce'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Only a string or a Closure is a nonce source: a string that happens to name a function is a plain value and
     * is never called. The result is used only if it is a string (its format is checked where it is emitted).
     */
    public static function nonceFrom(mixed $binding): ?string
    {
        try {
            $nonce = $binding instanceof \Closure ? $binding() : $binding;
        } catch (\Throwable) {
            return null;
        }

        return is_string($nonce) ? $nonce : null;
    }

    public static function registerAssets(): void
    {
        $list = AssetList::getInstance();
        $options = ['minify' => true, 'combine' => true];

        $list->register('css', 'cookie-manager-kit/css', 'css/cmk.css', $options, self::PACKAGE);
        $list->register('javascript', 'cookie-manager-kit/runtime', 'js/cmk-runtime.js', $options, self::PACKAGE);
        $list->register('javascript', 'cookie-manager-kit/dashboard', 'js/cmk-dashboard.js', ['minify' => true, 'combine' => false], self::PACKAGE);
    }

    public static function onBeforeRender(): void
    {
        $page = Page::getCurrentPage();

        if (!$page || $page->isError() || $page->isAdminArea() || $page->isEditMode() || $page->isMasterCollection()) {
            return;
        }

        if (in_array((string) $page->getCollectionPath(), self::EXCLUDED_PATHS, true)) {
            return;
        }

        $locale = Catalog::resolveLocale((string) Localization::activeLocale());
        $controller = $page->getPageController();

        self::registerAssets();

        $nonce = self::nonce();
        $boot = LoaderScript::boot(
            Catalog::policyVersion(),
            Catalog::handles(),
            Catalog::lifetimeDays(),
            self::path('/cookie-manager/config/' . $locale . '.json') . '?v=' . Catalog::hash(),
            self::path('/cookie-manager/consent'),
            $nonce,
        );

        $controller->addHeaderItem(LoaderScript::inline($boot, $nonce));
        $controller->requireAsset('css', 'cookie-manager-kit/css');
        $controller->requireAsset('javascript', 'cookie-manager-kit/runtime');

        if (!self::$outputHooked) {
            self::$outputHooked = true;
            app('director')->addListener('on_page_output', [self::class, 'onPageOutput']);
        }
    }

    /**
     * Concrete prints the SEO tracking snippets itself, in the form the admin pasted them. Find them verbatim in the
     * finished page and make them inert. If the analytics category has been deleted the snippet is still made
     * inert and simply never activates, which is the safe failure.
     */
    public static function onPageOutput($event): void
    {
        $contents = (string) $event->getArgument('contents');
        $repository = app('site')->getSite()->getConfigRepository();
        $changed = false;
        $nonce = self::nonce();

        foreach (['seo.tracking.code.header', 'seo.tracking.code.footer'] as $key) {
            $code = trim((string) $repository->get($key));

            if ($code !== '' && str_contains($contents, $code)) {
                $contents = str_replace($code, TrackingCode::makeInert($code, self::TRACKING_CATEGORY, $nonce), $contents);
                $changed = true;
            }
        }

        if ($changed) {
            $event->setArgument('contents', $contents);
        }
    }
}
