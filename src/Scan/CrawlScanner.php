<?php

namespace CookieManagerKit\Scan;

/**
 * Fetches each page as an anonymous visitor and reads it; it runs no JavaScript. A page that redirects, errors, times
 * out or is refused counts as failed, and so does every page left when the time budget runs out, because a crawl
 * that did not see everything must not conclude anything has disappeared. A truncated page list, or a crawl that
 * scanned nothing, is likewise reported incomplete.
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

        foreach (array_values($context->urls) as $index => $url) {
            if ($clock() - $started >= $this->budgetSeconds) {
                $failed += $total - $index;

                break;
            }

            try {
                $response = $this->fetcher->fetch($url);
            } catch (UnsafeUrl|FetchFailed) {
                $failed++;

                continue;
            }

            $ok = $response->status >= 200 && $response->status < 300;

            if ($ok) {
                $scanned++;
            } else {
                $failed++;
            }

            $items = CookieHeader::findings($response->cookies(), $context->siteHost, $url);

            if ($ok && $response->isHtml()) {
                $items = [...$items, ...PageParser::findings($response->body, $url, $context->siteHost)];
            }

            foreach ($items as $item) {
                $found[$item->kind . "\0" . $item->name . "\0" . $item->host] ??= $item;
            }
        }

        $complete = $failed === 0 && $scanned > 0 && !$context->truncated;

        return new ScanResult(array_values($found), $scanned, $failed, $complete);
    }
}
