<?php

namespace CookieManagerKit\Scan;

use Concrete\Core\Page\Page;
use Concrete\Core\Page\PageList;
use Concrete\Core\Support\Facade\Url;

/**
 * Concrete's published, non-system pages as absolute URLs. The host always comes from the configured base URL: the
 * URL Concrete resolves for a page can reflect the request's Host header, and the crawler must never be pointed at
 * a host a request chose.
 *
 * At most LIMIT pages are listed. The crawl cap (Settings, at most 500) is far below that, so a site with more pages
 * than LIMIT is always seen as truncated by the runner and never has findings marked gone.
 *
 * External-link pages are skipped: their URL is not a page of this site.
 */
final class PublishedPages implements UrlSource
{
    private const LIMIT = 5000;

    public function __construct(private readonly string $baseUrl) {}

    public function all(): array
    {
        $list = new PageList();
        $list->setSiteTreeToAll();
        $list->ignorePermissions();
        $list->sortByCollectionIDAscending();

        $pagination = $list->getPagination();
        $pagination->setMaxPerPage(self::LIMIT);
        // The pager reads the current page from the request (?ccm_paging_p=), which a web request could set.
        $pagination->setCurrentPage(1);

        $urls = [];

        foreach ($pagination->getCurrentPageResults() as $page) {
            if (!$page instanceof Page || $page->isError() || $page->isExternalLink()) {
                continue;
            }

            $url = self::rebase((string) Url::to($page), $this->baseUrl);

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Keeps the path and query of $url and puts them on $baseUrl's scheme, host and port.
     */
    public static function rebase(string $url, string $baseUrl): ?string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return null;
        }

        $base = parse_url($baseUrl);

        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            return null;
        }

        if ($parts !== [] && isset($parts['scheme']) && !isset($parts['host'])) {
            return null;
        }

        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        $path = (string) ($parts['path'] ?? '/');
        $path = $path === '' ? '/' : $path;

        return $origin . (str_starts_with($path, '/') ? '' : '/') . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
