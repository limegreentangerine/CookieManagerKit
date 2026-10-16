<?php

namespace CookieManagerKit\Scan;

interface Fetcher
{
    /**
     * @throws UnsafeUrl   when the URL is not one the crawler may request
     * @throws FetchFailed when the request fails or the response is too large
     */
    public function fetch(string $url): FetchResult;
}
