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
 * Only the current site's pages are listed. External-link pages are skipped: their URL is not a page of this site.
 * Pages that cannot be listed (an error page, an unusable URL, anything beyond LIMIT) are counted by dropped(), and the
 * runner then treats the crawl as not having seen everything.
 *
 * Members-only pages answer an anonymous visitor with a 403 or a redirect, so they fail when fetched and the crawl is
 * incomplete (nothing goes `gone`) until they are added to the exclude list.
 */
final class PublishedPages implements UrlSource
{
    private const LIMIT = 5000;

    private int $dropped = 0;

    public function __construct(private readonly string $baseUrl) {}

    public function dropped(): int
    {
        return $this->dropped;
    }

    public function all(): array
    {
        $list = new PageList();
        $list->setSiteTreeObject(app('site')->getSite());
        $list->ignorePermissions();
        $list->sortByCollectionIDAscending();

        $pagination = $list->getPagination();
        $pagination->setMaxPerPage(self::LIMIT);
        // The pager reads the current page from the request (?ccm_paging_p=), which a web request could set.
        $pagination->setCurrentPage(1);

        $resolved = [];

        foreach ($pagination->getCurrentPageResults() as $page) {
            if (!$page instanceof Page) {
                $resolved[] = null;

                continue;
            }

            if ($page->isExternalLink()) {
                continue;
            }

            $resolved[] = $page->isError() ? null : self::rebase((string) Url::to($page), $this->baseUrl);
        }

        $listed = self::summarise($resolved, (int) $pagination->getNbResults());
        $this->dropped = $listed['dropped'];

        return $listed['urls'];
    }

    /**
     * @param list<string|null> $resolved one entry per listed page: its URL, or null when it could not be turned into one
     * @param int               $total    how many pages the list holds in all, including those beyond the window
     *
     * @return array{urls: list<string>, dropped: int}
     */
    public static function summarise(array $resolved, int $total): array
    {
        $urls = array_values(array_filter($resolved, static fn(?string $url): bool => $url !== null));
        $beyondWindow = max(0, $total - self::LIMIT);

        return ['urls' => $urls, 'dropped' => count($resolved) - count($urls) + $beyondWindow];
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
