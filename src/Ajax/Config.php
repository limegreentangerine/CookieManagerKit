<?php

namespace CookieManagerKit\Ajax;

use Concrete\Core\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use CookieManagerKit\Support\{Catalog, ConfigRequest, ErrorLog, Output};

/**
 * Serves the per-locale config the banner renders from. Public, read-only and identical for every visitor, so it
 * is cacheable. The requested locale is only ever matched against the site's own locales.
 */
class Config
{
    public function serve(): Response
    {
        $request = app(Request::class);

        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return self::response('{"error":"invalid"}', 405, ['Cache-Control' => 'no-store']);
        }

        try {
            $config = Catalog::config(ConfigRequest::localeFromPath($request->getPathInfo()) ?? '');
            $etag = '"' . $config['version'] . '"';
            $headers = ['ETag' => $etag, 'Cache-Control' => 'public, max-age=300, must-revalidate'];

            if (ConfigRequest::matchesEtag($request->headers->get('If-None-Match'), $etag)) {
                return self::response('', 304, $headers);
            }

            return self::response(Output::json($config), 200, $headers);
        } catch (\Throwable $e) {
            ErrorLog::record($e);

            return self::response('{"error":"unavailable"}', 500, ['Cache-Control' => 'no-store']);
        }
    }

    /**
     * @param array<string, string> $extra
     */
    private static function response(string $body, int $status, array $extra = []): Response
    {
        return new Response($body, $status, $extra + [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="config.json"',
        ]);
    }
}
