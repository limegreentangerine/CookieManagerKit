<?php

namespace CookieManagerKit\Ajax;

use Concrete\Core\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use CookieManagerKit\Support\{BotFilter, Catalog, ErrorLog, Output, RateLimit, RequestGuard, StatsEvent, StatsStore};

/**
 * Receives anonymous banner events (shown, accept_all, reject_all, custom, changed) and counts them. Public and
 * cacheable pages cannot carry a CSRF token, so it is defensive like the consent endpoint: same-origin only, JSON
 * only, a strict schema, a size cap and a per-address rate limit. It stores nothing but a counter, answers with a
 * fixed body and never reflects anything from the request.
 */
class Event
{
    private const PER_MINUTE = 120;

    private function status(Request $request): int
    {
        if (!$request->isMethod('POST')) {
            return 405;
        }

        if (!RequestGuard::isSameOrigin($request->headers->get('Origin'), $request->headers->get('Referer'), $request->getHttpHost())) {
            return 403;
        }

        if (!RateLimit::allow('stats/' . sha1((string) $request->getClientIp()), self::PER_MINUTE)) {
            return 429;
        }

        $parsed = StatsEvent::parse($request->headers->get('Content-Type'), (string) $request->getContent());

        // A stale tab (older policy version) must not pollute the current counts.
        if ($parsed === null || $parsed['v'] !== Catalog::policyVersion()) {
            return 400;
        }

        // Acknowledged but not counted, so a crawler gets no signal to adapt to.
        if (BotFilter::isBot($request->headers->get('User-Agent'))) {
            return 200;
        }

        StatsStore::increment(gmdate('Y-m-d'), Catalog::resolveLocale($parsed['l']), $parsed['v'], $parsed['e']);

        return 200;
    }

    public function record(): Response
    {
        try {
            $status = $this->status(app(Request::class));
        } catch (\Throwable $e) {
            $status = 500;
            ErrorLog::record($e);
        }

        return new Response(
            Output::json($status === 200 ? ['ok' => true] : ['error' => 'invalid']),
            $status,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ],
        );
    }
}
