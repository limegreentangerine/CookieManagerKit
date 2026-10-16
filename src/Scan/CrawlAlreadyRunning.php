<?php

namespace CookieManagerKit\Scan;

/**
 * Raised by CrawlRunner::run() when another crawl is still running. Nothing was started or recorded. Callers (the
 * scheduled task, the dashboard's "run now") should catch it and show its fixed message.
 */
final class CrawlAlreadyRunning extends \RuntimeException {}
