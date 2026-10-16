<?php

namespace CookieManagerKit\Scan;

interface SnapshotSource
{
    /**
     * @throws FetchFailed
     */
    public function fetch(): string;
}
