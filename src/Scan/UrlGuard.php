<?php

namespace CookieManagerKit\Scan;

/**
 * Decides which URLs the crawler may request: only the site's own canonical host, same scheme and port, no
 * credentials. The host is resolved once, when the guard is made, and every request is pinned to that address, so a
 * DNS answer that changes mid-run cannot redirect the crawler.
 *
 * By default the site must resolve to a public address: loopback, private (RFC 1918), shared (100.64/10), IETF
 * protocol (192.0.0.0/24), benchmarking (198.18/15), link-local (cloud metadata), "this network", multicast,
 * reserved and broadcast addresses are refused. For a local development site, `$allowPrivateNetwork` allows ONLY
 * loopback and RFC 1918 addresses; the never-legitimate ranges stay refused. Later tasks wire the flag from the
 * config key `cookie_manager_kit.scan.allow_private_network`.
 */
final class UrlGuard
{
    /** Never a legitimate site address, whatever the configuration. */
    private const ALWAYS_REFUSED = [
        '0.0.0.0/8',
        '169.254.0.0/16',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /** Refused by default; the first four are the only ranges `$allowPrivateNetwork` permits. */
    private const NON_PUBLIC = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '100.64.0.0/10',
        '192.0.0.0/24',
        '198.18.0.0/15',
    ];

    private const DEVELOPMENT_ALLOWED = 4;

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

    private static function isRefused(string $ip, bool $allowPrivateNetwork): bool
    {
        $long = ip2long($ip);

        if ($long === false || self::inAny($long, self::ALWAYS_REFUSED)) {
            return true;
        }

        $nonPublic = $allowPrivateNetwork ? array_slice(self::NON_PUBLIC, self::DEVELOPMENT_ALLOWED) : self::NON_PUBLIC;

        return self::inAny($long, $nonPublic);
    }

    /**
     * @param list<string> $ranges
     */
    private static function inAny(int $long, array $ranges): bool
    {
        foreach ($ranges as $range) {
            [$base, $bits] = explode('/', $range);
            $mask = (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;

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
    public static function forSite(string $baseUrl, ?callable $resolver = null, bool $allowPrivateNetwork = false): self
    {
        $parts = self::parse($baseUrl);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? Host::normalise($parts['host'] ?? null) : null;

        if ($host === null || !in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || ($parts['port'] ?? null) === 0) {
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

        if (self::isRefused($ip, $allowPrivateNetwork)) {
            throw new UnsafeUrl('The site host resolves to an address the scanner may not request.');
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
