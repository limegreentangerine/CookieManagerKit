<?php

namespace CookieManagerKit\Ajax;

use Concrete\Core\Http\Request;
use CookieManagerKit\Support\{Catalog, ConsentRequest, Output, RateLimit, RequestGuard};
use Symfony\Component\HttpFoundation\Response;
use CookieManagerKit\Consent\{ConsentChoice, ConsentRecorder};

/**
 * Receives the choice a visitor made. Public and cacheable pages cannot carry a CSRF token, so it is defensive
 * instead: same-origin only, JSON only, a strict schema with an allowlist of category handles, a size cap and a
 * per-address rate limit. It answers with a fixed body and never reflects anything from the request.
 */
class Consent
{
    private const PER_MINUTE = 30;

    private function status(Request $request): int
    {
        if (!$request->isMethod('POST')) {
            return 405;
        }

        if (!RequestGuard::isSameOrigin($request->headers->get('Origin'), $request->headers->get('Referer'), $request->getHttpHost())) {
            return 403;
        }

        if (!RateLimit::allow('consent/' . sha1((string) $request->getClientIp()), self::PER_MINUTE)) {
            return 429;
        }

        $parsed = ConsentRequest::parse($request->headers->get('Content-Type'), (string) $request->getContent(), Catalog::handles());

        if ($parsed === null || $parsed['v'] !== Catalog::policyVersion()) {
            return 400;
        }

        app(ConsentRecorder::class)->record(new ConsentChoice($parsed['v'], $parsed['c']));

        return 200;
    }

    public function record(): Response
    {
        try {
            $status = $this->status(app(Request::class));
        } catch (\Throwable) {
            $status = 500;
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
