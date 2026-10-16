<?php

namespace CookieManagerKit\Scan;

/**
 * Fetches each page as an anonymous visitor and reads it; it runs no JavaScript. A page that redirects, errors, times
 * out or is refused counts as failed, and so does every page left when the time budget runs out, because a crawl
 * that did not see everything must not conclude anything has disappeared. A crawl that scanned nothing is likewise
 * reported incomplete. The budget is a soft limit: it is checked before each fetch, so one slow fetch can overrun it.
 * Findings carry only the page's path as their label, never its query string.
 */
final class CrawlScanner implements Scanner
{
    public function __construct(
        private readonly Fetcher $fetcher,
        private readonly ?\Closure $clock = null,
        private readonly int $budgetSeconds = 240,
    ) {}

    public function scan(ScanContext $context): ScanResult
    {
        $clock = $this->clock ?? static fn(): int => time();
        $started = $clock();
        $found = [];
        $scanned = 0;
        $failed = 0;
        $total = count($context->urls);
        $budgetHit = false;

        foreach (array_values($context->urls) as $index => $url) {
            if ($clock() - $started >= $this->budgetSeconds) {
                $failed += $total - $index;
                $budgetHit = true;

                break;
            }

            try {
                $response = $this->fetcher->fetch($url);
            } catch (UnsafeUrl|FetchFailed) {
                $failed++;

                continue;
            }

            $label = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
            $ok = $response->status >= 200 && $response->status < 300;

            if ($ok) {
                $scanned++;
            } else {
                $failed++;
            }

            $items = CookieHeader::findings($response->cookies(), $context->siteHost, $label);

            if ($ok && $response->isHtml()) {
                $items = [...$items, ...PageParser::findings($response->body, $label, $context->siteHost)];
            }

            foreach ($items as $item) {
                $found[$item->kind . "\0" . $item->name . "\0" . $item->host] ??= $item;
            }
        }

        $complete = $scanned > 0 && !$budgetHit;

        return new ScanResult(array_values($found), $scanned, $failed, $complete);
    }
}
