<?php

namespace CookieManagerKit\Scan;

/**
 * The scan-mode credential: `<runId>.<expiresAt>.<hmac>`. It only lets a browser that holds it post scan reports for
 * that one run until it expires. The HMAC is compared in constant time, and nothing is accepted when there is no
 * server-side secret to sign with.
 */
final class ScanToken
{
    public const COOKIE = 'cmk_scan_token';
    public const MARKER = 'cmk_scan';
    public const LIFETIME = ScanRuns::SESSION_SECONDS;

    private static function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', 'cmk-scan|' . $payload, $secret);
    }

    public static function issue(int $runId, int $now, string $secret): string
    {
        $payload = $runId . '.' . ($now + self::LIFETIME);

        return $payload . '.' . self::sign($payload, $secret);
    }

    /**
     * @return array{run: int, expires: int}|null
     */
    public static function verify(mixed $token, int $now, string $secret): ?array
    {
        if (!is_string($token) || $secret === '' || preg_match('/^(\d{1,9})\.(\d{10})\.([a-f0-9]{64})\z/', $token, $match) !== 1) {
            return null;
        }

        $payload = $match[1] . '.' . $match[2];

        if (!hash_equals(self::sign($payload, $secret), $match[3]) || (int) $match[2] <= $now) {
            return null;
        }

        return ['run' => (int) $match[1], 'expires' => (int) $match[2]];
    }

    /**
     * The per-installation pepper Concrete uses for its own tokens; empty on a site that has none, which disables scan mode.
     */
    public static function secret(): string
    {
        return (string) app('config/database')->get('concrete.security.token.validation');
    }
}
