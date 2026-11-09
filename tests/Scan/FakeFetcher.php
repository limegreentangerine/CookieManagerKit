<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use CookieManagerKit\Scan\Fetcher;
use CookieManagerKit\Scan\FetchResult;

/**
 * Answers from a fixed map of URL to FetchResult (or a Throwable to throw), and remembers what was asked.
 */
final class FakeFetcher implements Fetcher
{
    /** @var list<string> */
    public array $requested = [];

    /**
     * @param array<string, FetchResult|\Throwable> $responses
     */
    public function __construct(private array $responses) {}

    public function fetch(string $url): FetchResult
    {
        $this->requested[] = $url;
        $response = $this->responses[$url] ?? new \CookieManagerKit\Scan\FetchFailed('no such page');

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    public static function page(string $html, array $headers = [], int $status = 200): FetchResult
    {
        return new FetchResult($status, ['content-type' => ['text/html; charset=utf-8']] + $headers, $html);
    }
}
