<?php

namespace CookieManagerKit\Ajax;

use Concrete\Core\Http\Request;
use CookieManagerKit\Scan\ScanToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the beacon script, but only to a browser that holds a valid scan-mode token. Everyone else gets an empty
 * 204, so the page HTML (which never mentions scan mode) stays identical for every visitor and cacheable.
 */
class ScanBeacon
{
    public function script(): Response
    {
        $valid = false;

        try {
            $request = app(Request::class);
            $valid = $request->isMethod('GET') || $request->isMethod('HEAD')
                ? ScanToken::verify($request->cookies->get(ScanToken::COOKIE), time(), ScanToken::secret()) !== null
                : false;
        } catch (\Throwable) {
        }

        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store'];

        if (!$valid) {
            return new Response('', 204, $headers);
        }

        $source = (string) @file_get_contents(dirname(__DIR__, 2) . '/js/cmk-beacon.js');

        return new Response($source, 200, $headers + ['Content-Type' => 'application/javascript; charset=utf-8']);
    }
}
