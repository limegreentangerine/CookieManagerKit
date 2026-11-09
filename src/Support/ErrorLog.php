<?php

namespace CookieManagerKit\Support;

use CookieManagerKit\Log\CookieManagerLogger;

/**
 * Server-side error logging for the public endpoints. Exception messages can carry request-derived text, so the
 * line is flattened (no control characters or newlines) and capped before it reaches the `cookie_manager` log
 * channel. Logging can never change a response: any failure inside record() is swallowed.
 */
final class ErrorLog
{
    private const MAX_LENGTH = 300;

    public static function format(\Throwable $e): string
    {
        $message = mb_scrub($e->getMessage(), 'UTF-8');
        $message = preg_replace('/[\p{Cc}\x{2028}\x{2029}]+/u', ' ', $message) ?? '';

        return mb_substr('Cookie Manager: ' . $e::class . ': ' . trim($message), 0, self::MAX_LENGTH, 'UTF-8');
    }

    public static function record(\Throwable $e): void
    {
        try {
            \Core::make(CookieManagerLogger::class)->getLogger()->error(self::format($e));
        } catch (\Throwable) {
        }
    }
}
