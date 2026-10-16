<?php

namespace CookieManagerKit\Scan;

/**
 * A request that did not produce a usable response (transport error, over the size cap).
 */
class FetchFailed extends \RuntimeException {}
