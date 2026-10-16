<?php

namespace CookieManagerKit\Scan;

interface UrlSource
{
    /**
     * @return list<string> absolute URLs on the site's own canonical host
     */
    public function all(): array;
}
