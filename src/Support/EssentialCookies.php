<?php

namespace CookieManagerKit\Support;

/**
 * Cookies ConcreteCMS needs to function. They are strictly necessary: the package never blocks, sets or deletes
 * them, and the cookie-clearing and scanner code consult this list first. The session name is read each time
 * because another package may change `concrete.session.name` at runtime; nothing here is stored or cached.
 */
final class EssentialCookies
{
    public const CONSENT_COOKIE = 'cmk_consent';
    public const DEFAULT_SESSION_NAME = 'CONCRETE';

    private const FIXED = ['ccmAuthUserHash', 'ConcreteSitemap-expand', 'dashboardPanelStatus', self::CONSENT_COOKIE];

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
}
