<?php

namespace CookieManagerKit\Consent;

/**
 * Stores a visitor's choice. Returns the visitor's consent ID, or null when nothing was stored. Never throws: a
 * failure to record must not change what the visitor sees.
 */
interface ConsentRecorder
{
    public function record(ConsentChoice $choice): ?string;
}
