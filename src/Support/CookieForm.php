<?php

namespace CookieManagerKit\Support;

use ClassKit\Entity\Core\BaseEntity;
use CookieManagerKit\Entity\{Cookie, Service};

/**
 * The cookie rows of the service form. Pure: parse() cleans what was posted, apply() changes the entities it is
 * given and persists nothing (the service cascades to its cookies).
 */
final class CookieForm
{
    private const BLANK_ROWS = 3;
    public const MAX_ROWS = 500;

    private static function persistedId(Cookie $cookie): ?int
    {
        $property = new \ReflectionProperty(BaseEntity::class, 'id');

        return $property->isInitialized($cookie) ? $cookie->getID() : null;
    }

    /** Rows the form renders: every stored cookie, plus a few blank ones while there is room under the cap. */
    public static function formRows(int $stored): int
    {
        return $stored + max(0, min(self::BLANK_ROWS, self::MAX_ROWS - $stored));
    }

    /** True when more rows were posted than the form can ever render, so parse() would silently drop the rest. */
    public static function exceeds(mixed $posted): bool
    {
        return is_array($posted) && count($posted) > self::MAX_ROWS;
    }

    /** True when the service already stores more cookies than the form can show and save back. */
    public static function overflows(Service $service): bool
    {
        return count($service->getCookies()) > self::MAX_ROWS;
    }

    /**
     * @param list<string> $locales
     *
     * @return list<array{id: ?int, name: string, days: int, purposes: array<string, string>}>
     */
    public static function parse(mixed $posted, array $locales): array
    {
        $rows = [];
        $seen = [];

        foreach (is_array($posted) ? $posted : [] as $input) {
            if (!is_array($input)) {
                continue;
            }

            $name = Text::plain($input['name'] ?? '', Cookie::MAX_NAME);
            $key = mb_strtolower($name);

            if ($name === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $id = $input['id'] ?? '';
            $given = is_array($input['purpose'] ?? null) ? $input['purpose'] : [];
            $purposes = [];

            foreach ($locales as $locale) {
                $purposes[$locale] = Text::multiline($given[$locale] ?? '', 500);
            }

            $rows[] = [
                'id' => is_string($id) && preg_match('/^\d{1,10}\z/', $id) === 1 ? (int) $id : null,
                'name' => $name,
                'days' => Cookie::clampDays($input['days'] ?? 0),
                'purposes' => $purposes,
            ];

            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param list<array{id: ?int, name: string, days: int, purposes: array<string, string>}> $rows
     *
     * @throws \InvalidArgumentException when the rows would swap, reuse or double up cookie names
     * @throws \OverflowException        when the service stores more cookies than the form can carry, because saving
     *                                   would delete the ones it could not show
     */
    public static function apply(Service $service, array $rows): void
    {
        if (self::overflows($service)) {
            throw new \OverflowException('Too many cookies for the form to save safely.');
        }

        $byId = [];
        $byName = [];
        $originals = [];

        foreach ($service->getCookies() as $cookie) {
            $id = self::persistedId($cookie);

            if ($id !== null) {
                $byId[$id] = $cookie;
            }

            $originals[spl_object_id($cookie)] = mb_strtolower($cookie->getName());
            $byName[mb_strtolower($cookie->getName())] = $cookie;
        }

        // Validate first: nothing on the service changes unless every row is safe to flush.
        $plan = [];
        $claimed = [];

        foreach ($rows as $row) {
            $cookie = $row['id'] !== null ? ($byId[$row['id']] ?? null) : null;
            $cookie ??= $byName[mb_strtolower($row['name'])] ?? null;

            if ($cookie !== null) {
                $oid = spl_object_id($cookie);

                if (isset($claimed[$oid])) {
                    throw new \InvalidArgumentException('Two rows resolve to the same cookie.');
                }

                $claimed[$oid] = true;

                foreach ($originals as $other => $original) {
                    if ($other !== $oid && $original === mb_strtolower($row['name'])) {
                        throw new \InvalidArgumentException('Cookies cannot swap or reuse names in one save.');
                    }
                }
            }

            $plan[] = [$cookie, $row];
        }

        $kept = [];

        foreach ($plan as [$cookie, $row]) {
            if ($cookie === null) {
                $cookie = $service->addCookie($row['name']);
            } else {
                $cookie->setName($row['name']);
            }

            if ($cookie === null) {
                continue;
            }

            $cookie->setDurationDays($row['days']);

            foreach ($row['purposes'] as $locale => $purpose) {
                if ($purpose === '') {
                    $existing = $cookie->getContent((string) $locale);

                    if ($existing) {
                        $cookie->getContents()->removeElement($existing);
                    }

                    continue;
                }

                $cookie->setContent((string) $locale, $purpose);
            }

            $kept[spl_object_id($cookie)] = true;
        }

        foreach ($service->getCookies()->toArray() as $cookie) {
            if (!isset($kept[spl_object_id($cookie)])) {
                $service->getCookies()->removeElement($cookie);
            }
        }
    }
}
