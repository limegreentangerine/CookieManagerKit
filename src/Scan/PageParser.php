<?php

namespace CookieManagerKit\Scan;

use Dom\HTMLDocument;
use CookieManagerKit\Entity\ScanFinding;

/**
 * Reads the third-party hosts a page loads. Uses the WHATWG HTML parser (like TrackingCode), so the tags found are
 * exactly those a browser would see; regex over markup is not used. Nothing is executed or fetched.
 */
final class PageParser
{
    private const MAX_FINDINGS = 200;
    private const MAX_BYTES = 2000000;
    private const MAX_TAGS = 20000;
    private const SCRIPT_TYPES = ['text/javascript', 'application/javascript', 'module'];
    private const LINK_RELATIONS = ['stylesheet', 'preload', 'prefetch', 'preconnect', 'dns-prefetch', 'modulepreload', 'icon'];

    /**
     * The parser takes quadratic time on very deep nesting, so the markup it sees is capped in size and tag count.
     * A truncated page still yields the findings from the part that was kept.
     */
    private static function bound(string $html): string
    {
        $html = substr($html, 0, self::MAX_BYTES);
        $offset = 0;

        for ($i = 0; $i < self::MAX_TAGS; $i++) {
            $at = strpos($html, '<', $offset);

            if ($at === false) {
                return $html;
            }

            $offset = $at + 1;
        }

        $at = strpos($html, '<', $offset);

        return $at === false ? $html : substr($html, 0, $at);
    }

    private static function isResourceLink(string $rel): bool
    {
        foreach (preg_split('/\s+/', strtolower(trim($rel))) ?: [] as $token) {
            if (in_array($token, self::LINK_RELATIONS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Finding>
     */
    public static function findings(string $html, string $pageUrl, string $siteHost): array
    {
        if (trim($html) === '') {
            return [];
        }

        $html = self::bound($html);

        try {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        } catch (\Throwable) {
            return [];
        }

        $found = [];
        $add = static function (string $kind, ?string $host) use (&$found, $siteHost, $pageUrl): void {
            if ($host === null || Host::sameSite($host, $siteHost) || count($found) >= self::MAX_FINDINGS) {
                return;
            }

            $key = $kind . ' ' . $host;

            if (!isset($found[$key])) {
                $found[$key] = new Finding($kind, $host, $host, $pageUrl);
            }
        };

        foreach ($document->querySelectorAll('script, iframe[src], img[src], link[href]') as $element) {
            if (count($found) >= self::MAX_FINDINGS) {
                break;
            }

            $tag = strtolower($element->localName);

            if ($tag === 'script') {
                if ($element->hasAttribute('src')) {
                    $add(ScanFinding::SCRIPT, Host::fromUrl($element->getAttribute('src')));

                    continue;
                }

                $type = strtolower(trim((string) $element->getAttribute('type')));

                if ($type !== '' && !in_array($type, self::SCRIPT_TYPES, true)) {
                    continue;
                }

                preg_match_all('#(?:https?:)?//([A-Za-z0-9][A-Za-z0-9.-]{0,252}\.[A-Za-z0-9-]{2,63})(?![A-Za-z0-9.-])#', (string) $element->textContent, $matches);

                foreach ($matches[1] as $host) {
                    $add(ScanFinding::SCRIPT, Host::normalise($host));
                }
            } elseif ($tag === 'iframe') {
                $add(ScanFinding::IFRAME, Host::fromUrl($element->getAttribute('src')));
            } elseif ($tag === 'img') {
                $add(ScanFinding::IMAGE, Host::fromUrl($element->getAttribute('src')));
            } elseif ($tag === 'link' && self::isResourceLink((string) $element->getAttribute('rel'))) {
                $add(ScanFinding::RESOURCE, Host::fromUrl($element->getAttribute('href')));
            }
        }

        return array_values($found);
    }
}
