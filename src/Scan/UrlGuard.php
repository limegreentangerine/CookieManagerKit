<?php

namespace CookieManagerKit\Scan;

/**
 * Decides which URLs the crawler may request: only the site's own canonical host, same scheme and port, no
 * credentials. The host is resolved once, when the guard is made, and every request is pinned to that address, so a
 * DNS answer that changes mid-run cannot redirect the crawler. A development site on a private or loopback address
 * is allowed because that address is the site's own; addresses that are never a real web server (link-local,
 * "this network", multicast, reserved, broadcast) are always refused.
 */
final class UrlGuard
{
    private const REFUSED = [
        '0.0.0.0/8',
        '169.254.0.0/16',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $scheme,
        private readonly string $ip,
    ) {}

    /**
     * parse_url, after refusing the characters browsers, cURL and parse_url read differently (backslash, whitespace,
     * control and non-ASCII bytes), which is how "host\\@evil" style URLs smuggle a second host past a check.
     *
     * @return array<string, mixed>|null
     */
    private static function parse(string $url): ?array
    {
        if (preg_match('/[\\\\\s\x00-\x1f\x7f-\xff]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        return is_array($parts) ? $parts : null;
    }

    private static function isRefused(string $ip): bool
    {
        $long = ip2long($ip);

        if ($long === false) {
            return true;
        }

        foreach (self::REFUSED as $range) {
            [$base, $bits] = explode('/', $range);
            $mask = $bits === '0' ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;

            if (($long & $mask) === (ip2long($base) & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param (callable(string): ?string)|null $resolver returns an IPv4 address, or null when the host does not resolve
     *
     * @throws UnsafeUrl
     */
    public static function forSite(string $baseUrl, ?callable $resolver = null): self
    {
        $parts = self::parse($baseUrl);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? Host::normalise($parts['host'] ?? null) : null;

        if ($host === null || !in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrl('The site URL is not a plain http(s) address.');
        }

        $resolver ??= static function (string $name): ?string {
            if (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $name;
            }

            $ip = gethostbyname($name);

            return $ip === $name ? null : $ip;
        };

        $ip = $resolver($host);

        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new UnsafeUrl('The site host does not resolve to an IPv4 address.');
        }

        if (self::isRefused($ip)) {
            throw new UnsafeUrl('The site host resolves to an address that is never a web server.');
        }

        return new self($host, (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)), $scheme, $ip);
    }

    /**
     * @throws UnsafeUrl
     */
    public function check(string $url): void
    {
        $parts = self::parse($url);

        if ($parts === null || isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrl('The URL is not a plain address.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        // The host must match the pinned name exactly. "example.com." (trailing dot) is the same name to DNS but is a
        // different name to cURL's resolve list, so it would be resolved afresh and escape the pin.
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== $this->scheme || $host !== $this->host || $port !== $this->port) {
            throw new UnsafeUrl('The URL is not on the site being scanned.');
        }
    }

    public function host(): string
    {
        return $this->host;
    }

    /**
     * The value for CURLOPT_RESOLVE: connect to the address resolved when the run began.
     */
    public function curlResolve(): string
    {
        return $this->host . ':' . $this->port . ':' . $this->ip;
    }
}
