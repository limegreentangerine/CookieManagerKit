<?php

namespace CookieManagerKit\Consent;

/**
 * Turns a raw User-Agent into a coarse "<browser>/<device>" bucket from fixed lists. The raw string is never kept:
 * it is long, unique-ish and hostile (it comes straight from the request).
 */
final class UserAgentFamily
{
    private static function browser(string $agent): string
    {
        // Order matters: Edge and Opera also say "Chrome", and Chrome also says "Safari".
        foreach ([
            'Edge' => '#\b(?:Edg|EdgA|EdgiOS|Edge)/#',
            'Opera' => '#\b(?:OPR|Opera)/#',
            'Firefox' => '#\b(?:Firefox|FxiOS)/#',
            'Chrome' => '#\b(?:Chrome|CriOS)/#',
            'Safari' => '#\bSafari/#',
        ] as $name => $pattern) {
            if (preg_match($pattern, $agent) === 1) {
                return $name;
            }
        }

        return 'Other';
    }

    private static function device(string $agent): string
    {
        if (preg_match('#iPad|Tablet|Android(?!.*Mobile)#i', $agent) === 1) {
            return 'tablet';
        }

        if (preg_match('#Mobi|iPhone|iPod|Android#i', $agent) === 1) {
            return 'mobile';
        }

        return 'desktop';
    }
    public static function of(?string $userAgent): string
    {
        $agent = $userAgent === null ? '' : substr($userAgent, 0, 512);

        if ($agent === '') {
            return 'Other/unknown';
        }

        return self::browser($agent) . '/' . self::device($agent);
    }
}
