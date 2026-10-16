<?php

namespace CookieManagerKit\Events;

use Concrete\Core\Page\Page;
use Concrete\Core\Asset\AssetList;
use Concrete\Core\Support\Facade\Url;
use Concrete\Core\Localization\Localization;
use CookieManagerKit\Support\{Catalog, ErrorLog, LoaderScript, TrackingCode};

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

    /** Set once a public page has passed the exclusion checks; onPageOutput only acts on such a request. */
    private static bool $active = false;

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

    private static function report(callable $onError, \Throwable $error): void
    {
        try {
            $onError($error);
        } catch (\Throwable) {
        }
    }

    /**
     * The gate is closed first, so a flag left open by an earlier request on a long-lived worker (a render that never
     * reached its page output) cannot make this request's excluded page act; it opens again only after the
     * exclusion checks below.
     */
    public static function beforeRender(?Page $page): void
    {
        self::$active = false;

        if (!$page instanceof Page || $page->isError() || $page->isAdminArea() || $page->isEditMode() || $page->isMasterCollection()) {
            return;
        }

        if (in_array((string) $page->getCollectionPath(), self::EXCLUDED_PATHS, true)) {
            return;
        }

        // From here on the tracking snippets of this page must be made inert, whatever else goes wrong.
        self::$active = true;

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
            self::path('/cookie-manager/event'),
            self::path('/cookie-manager/scan/beacon.js'),
        );

        $controller->addHeaderItem(LoaderScript::inline($boot, $nonce));
        $controller->requireAsset('css', 'cookie-manager-kit/css');
        $controller->requireAsset('javascript', 'cookie-manager-kit/runtime');
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

    /**
     * Both hooks run on every public page, so a Catalog, database or cache failure must never become a 500 for the
     * whole site: it is logged and the hook does nothing. Fail-open for the banner (no loader is injected, inert
     * scripts simply stay inert); the page output is left unmodified.
     *
     * @param (callable(\Throwable): void)|null $onError defaults to the package error log
     */
    public static function guarded(callable $run, ?callable $onError = null): void
    {
        try {
            $run();
        } catch (\Throwable $e) {
            try {
                ($onError ?? ErrorLog::record(...))($e);
            } catch (\Throwable) {
            }
        }
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    public static function reset(): void
    {
        self::$active = false;
    }

    public static function onBeforeRender(): void
    {
        self::reset();
        self::guarded(static fn() => self::beforeRender(Page::getCurrentPage()));
    }

    /**
     * Concrete prints the SEO tracking snippets itself, in the form the admin pasted them. Find them verbatim in the
     * finished page and make them inert. If the analytics category has been deleted the snippet is still made
     * inert and simply never activates, which is the safe failure. The listener is registered unconditionally; it
     * acts only on a page onBeforeRender accepted, and a snippet that cannot be made inert is removed (fail closed).
     */
    public static function onPageOutput($event): void
    {
        if (!self::$active) {
            return;
        }

        self::$active = false;

        self::guarded(static function () use ($event): void {
            $repository = app('site')->getSite()->getConfigRepository();
            $snippets = [];

            foreach (['seo.tracking.code.header', 'seo.tracking.code.footer'] as $key) {
                $snippets[] = trim((string) $repository->get($key));
            }

            $contents = (string) $event->getArgument('contents');
            $rewritten = self::rewriteSnippets($contents, $snippets, self::nonce(), ErrorLog::record(...));

            if ($rewritten !== $contents) {
                $event->setArgument('contents', $rewritten);
            }
        });
    }

    /**
     * Pure: makes each snippet found verbatim in $contents inert, all in one pass over the original contents. A snippet whose rewrite throws, or
     * comes back empty, is removed from the output rather than left live.
     *
     * @param list<string>                                     $snippets
     * @param callable(\Throwable): void                       $onError
     * @param (callable(string, string, ?string): string)|null $rewriter defaults to TrackingCode::makeInert
     */
    public static function rewriteSnippets(string $contents, array $snippets, ?string $nonce, callable $onError, ?callable $rewriter = null): string
    {
        $rewriter ??= TrackingCode::makeInert(...);
        $replacements = [];
        $ranges = [];

        foreach ($snippets as $code) {
            if ($code === '' || isset($replacements[$code])) {
                continue;
            }

            $at = strpos($contents, $code);

            if ($at === false) {
                continue;
            }

            for (; $at !== false; $at = strpos($contents, $code, $at + strlen($code))) {
                $ranges[] = ['start' => $at, 'end' => $at + strlen($code), 'code' => $code];
            }

            try {
                $replacements[$code] = $rewriter($code, self::TRACKING_CATEGORY, $nonce);
            } catch (\Throwable $e) {
                $replacements[$code] = '';
                self::report($onError, $e);
            }
        }

        // Resolve the occurrences in the ORIGINAL contents, then rebuild in one left-to-right pass, so a replacement
        // is never searched again and an earlier one cannot disturb a later snippet. An occurrence inside another
        // is absorbed (longest wins). Two that straddle each other (neither contains the other) cannot both be
        // honoured: their union is removed, which is the fail-closed outcome.
        usort($ranges, static fn(array $a, array $b): int => [$a['start'], $b['end']] <=> [$b['start'], $a['end']]);

        $kept = [];

        foreach ($ranges as $range) {
            $last = count($kept) - 1;

            if ($last < 0 || $range['start'] >= $kept[$last]['end']) {
                $kept[] = $range + ['conflict' => false];
            } elseif ($range['end'] > $kept[$last]['end']) {
                if (!$kept[$last]['conflict']) {
                    self::report($onError, new \RuntimeException('Overlapping tracking snippets were removed.'));
                }

                $kept[$last]['end'] = $range['end'];
                $kept[$last]['conflict'] = true;
            }
        }

        $out = '';
        $position = 0;

        foreach ($kept as $range) {
            $out .= substr($contents, $position, $range['start'] - $position)
                . ($range['conflict'] ? '' : $replacements[$range['code']]);
            $position = $range['end'];
        }

        return $kept === [] ? $contents : $out . substr($contents, $position);
    }
}
