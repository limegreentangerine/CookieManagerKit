<?php

namespace CookieManagerKit\Support;

use CookieManagerKit\Entity\{Cookie, Service};

/**
 * The cookie rows of the service form. Pure: parse() cleans what was posted, apply() changes the entities it is
 * given and persists nothing (the service cascades to its cookies).
 */
final class CookieForm
{
    public const MAX_ROWS = 50;

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
     */
    public static function apply(Service $service, array $rows): void
    {
        $byId = [];

        foreach ($service->getCookies() as $cookie) {
            try {
                $byId[$cookie->getID()] = $cookie;
            } catch (\Error) {
                // Not persisted yet, so it has no ID a posted row could refer to.
            }
        }

        $kept = [];

        foreach ($rows as $row) {
            $cookie = $row['id'] !== null ? ($byId[$row['id']] ?? null) : null;
            $cookie ??= $service->getCookie($row['name']);

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
