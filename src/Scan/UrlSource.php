<?php

namespace CookieManagerKit\Scan;

interface UrlSource
{
    /**
     * @return list<string> absolute URLs on the site's own canonical host
     */
    public function all(): array;

    /**
     * How many pages the source knew about but could not list (an error page, an unusable URL, pages beyond its
     * window), as of the last all() call. Any value above zero means the list is not the whole site, so the crawl
     * cannot conclude that anything has gone.
     */
    public function dropped(): int;
}
