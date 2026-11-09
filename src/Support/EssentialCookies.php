<?php

namespace CookieManagerKit\Support;

/**
 * Cookies ConcreteCMS needs to function. They are strictly necessary: the package never blocks, sets or deletes
 * them, and the cookie-clearing and scanner code consult this list first. The session name is read each time
 * because another package may change `concrete.session.name` at runtime; nothing here is stored or cached.
 */
final class EssentialCookies
{
    private const FIXED = ['ccmAuthUserHash', 'ConcreteSitemap-expand', 'dashboardPanelStatus', self::CONSENT_COOKIE];

    private const DEFAULT_REMEMBER_ME_SECONDS = 1209600;
    public const CONSENT_COOKIE = 'cmk_consent';
    public const DEFAULT_SESSION_NAME = 'CONCRETE';

    private static function days(int $days): string
    {
        return $days . ($days === 1 ? ' day' : ' days');
    }

    private static function rememberMeDuration(?callable $read): string
    {
        $read ??= static fn(string $key): mixed => app('config')->get($key);

        try {
            $seconds = $read('concrete.session.remember_me.lifetime');
        } catch (\Throwable) {
            $seconds = null;
        }

        $seconds = is_int($seconds) || (is_string($seconds) && ctype_digit($seconds)) ? (int) $seconds : 0;

        if ($seconds < 86400) {
            $seconds = self::DEFAULT_REMEMBER_ME_SECONDS;
        }

        $days = intdiv($seconds, 86400);

        return $days % 7 === 0 ? ($days / 7) . ($days === 7 ? ' week' : ' weeks') : self::days($days);
    }

    /**
     * @return list<string>
     */
    public static function sessionNames(mixed $configured, mixed $active = null): array
    {
        $names = [];

        foreach ([$configured, $active] as $candidate) {
            if (is_string($candidate) && preg_match('/^[A-Za-z0-9_.\-]{1,64}\z/', $candidate) === 1) {
                $names[] = $candidate;
            }
        }

        return $names === [] ? [self::DEFAULT_SESSION_NAME] : array_values(array_unique($names));
    }

    /**
     * @param (callable(string): mixed)|null $read Config reader; defaults to Concrete's config repository.
     *
     * @return list<string>
     */
    public static function sessionNamesNow(?callable $read = null): array
    {
        $read ??= static fn(string $key): mixed => app('config')->get($key);
        $active = session_status() === PHP_SESSION_ACTIVE ? session_name() : null;

        return self::sessionNames($read('concrete.session.name'), $active);
    }

    /**
     * @param (callable(string): mixed)|null $read
     *
     * @return list<string>
     */
    public static function names(?callable $read = null): array
    {
        $names = [];

        foreach (self::sessionNamesNow($read) as $session) {
            $names[] = $session;
            $names[] = $session . '_LOGIN';
        }

        return array_values(array_unique([...$names, 'ConcreteSitemapTreeID', ...self::FIXED]));
    }

    /**
     * @param (callable(string): mixed)|null $read
     */
    public static function isEssential(string $cookie, ?callable $read = null): bool
    {
        return in_array($cookie, self::names($read), true)
            || preg_match('/^ConcreteSitemapTreeID(?:-\d+)?\z/', $cookie) === 1;
    }

    /**
     * What a visitor is told about each always-allowed cookie: plain-English purpose and duration, in the order of
     * $names. Unknown names get empty strings.
     *
     * @param list<string>                   $names
     * @param (callable(string): mixed)|null $read  Config reader; defaults to Concrete's config repository.
     *
     * @return list<array{name: string, purpose: string, duration: string}>
     */
    public static function describe(array $names, int $consentLifetimeDays, ?callable $read = null): array
    {
        $sessions = self::sessionNamesNow($read);
        $rows = [];

        foreach ($names as $name) {
            $name = (string) $name;
            [$purpose, $duration] = match (true) {
                in_array($name, $sessions, true) => ['Keeps you signed in and your session working.', 'Session'],
                str_ends_with($name, '_LOGIN') && in_array(substr($name, 0, -6), $sessions, true) => ['Remembers that you signed in on this browser.', self::rememberMeDuration($read)],
                $name === 'ccmAuthUserHash' => ["Keeps you signed in if you chose 'stay signed in'.", self::rememberMeDuration($read)],
                preg_match('/^ConcreteSitemapTreeID(?:-\d+)?\z/', $name) === 1 => ['Remembers which site tree you were browsing in the dashboard.', 'Session'],
                $name === 'ConcreteSitemap-expand' => ['Remembers which sitemap branches are open in the dashboard.', 'Session'],
                $name === 'dashboardPanelStatus' => ['Remembers whether the dashboard panel is open.', 'Session'],
                $name === self::CONSENT_COOKIE => ['Remembers your cookie choices.', self::days($consentLifetimeDays)],
                default => ['', ''],
            };

            $rows[] = ['name' => $name, 'purpose' => $purpose, 'duration' => $duration];
        }

        return $rows;
    }
}
