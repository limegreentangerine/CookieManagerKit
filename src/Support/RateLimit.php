<?php

namespace CookieManagerKit\Support;

/**
 * Fixed-window counter in Concrete's expensive cache. The window start lives in the value, not in the cache
 * expiry: an expiry that moves on every save would let a steady trickle from one address accumulate forever.
 */
final class RateLimit
{
    /**
     * @param array<string, mixed>|null $state
     *
     * @return array{bool, array{start: int, used: int}}
     */
    public static function step(?array $state, int $now, int $limit, int $window): array
    {
        $start = is_int($state['start'] ?? null) ? $state['start'] : null;
        $used = is_int($state['used'] ?? null) ? $state['used'] : null;

        if ($start === null || $used === null || $now - $start >= $window) {
            $start = $now;
            $used = 0;
        }

        if ($used + 1 > $limit) {
            return [false, ['start' => $start, 'used' => $used]];
        }

        return [true, ['start' => $start, 'used' => $used + 1]];
    }

    public static function allow(string $key, int $limit, int $windowSeconds = 60): bool
    {
        $cache = app('cache/expensive');
        $item = $cache->getItem('cookie_manager_kit/rate/' . $key);
        $state = $item->isMiss() ? null : $item->get();

        [$allowed, $next] = self::step(is_array($state) ? $state : null, time(), $limit, $windowSeconds);

        $item->set($next);
        $item->expiresAfter($windowSeconds * 2);
        $cache->save($item);

        return $allowed;
    }
}
