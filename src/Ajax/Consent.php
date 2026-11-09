<?php

namespace CookieManagerKit\Ajax;

use Concrete\Core\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use CookieManagerKit\Consent\{ConsentChoice, ConsentRecorder, ConsentReply, UserAgentFamily};
use CookieManagerKit\Support\{Catalog, ConsentRequest, ErrorLog, Output, RateLimit, RequestGuard};

/**
 * Receives the choice a visitor made. Public and cacheable pages cannot carry a CSRF token, so it is defensive
 * instead: same-origin only, JSON only, a strict schema with an allowlist of category handles, a size cap and a
 * per-address rate limit. It records the choice in the audit log and answers with a fixed body (plus the visitor's own consent ID); it never
 * reflects anything from the request.
 */
class Consent
{
    private const PER_MINUTE = 30;

    /**
     * @return array{int, ?string} status and the visitor's consent ID
     */
    private function handle(Request $request): array
    {
        if (!$request->isMethod('POST')) {
            return [405, null];
        }

        if (!RequestGuard::isSameOrigin($request->headers->get('Origin'), $request->headers->get('Referer'), $request->getHttpHost())) {
            return [403, null];
        }

        if (!RateLimit::allow('consent/' . sha1((string) $request->getClientIp()), self::PER_MINUTE)) {
            return [429, null];
        }

        $parsed = ConsentRequest::parse($request->headers->get('Content-Type'), (string) $request->getContent(), Catalog::handles(), Catalog::siteLocales());

        if ($parsed === null || $parsed['v'] !== Catalog::policyVersion()) {
            return [400, null];
        }

        $id = app(ConsentRecorder::class)->record(new ConsentChoice(
            $parsed['v'],
            $parsed['c'],
            $parsed['trigger'],
            $parsed['locale'],
            UserAgentFamily::of($request->headers->get('User-Agent')),
            $parsed['i'],
        ));

        return [200, $id];
    }

    public function record(): Response
    {
        try {
            [$status, $id] = $this->handle(app(Request::class));
        } catch (\Throwable $e) {
            [$status, $id] = [500, null];
            ErrorLog::record($e);
        }

        return new Response(
            Output::json(ConsentReply::body($status, $id)),
            $status,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ],
        );
    }
}
