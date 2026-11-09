<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Translation;

use CookieManagerKit\Translation\Slot;

final class FakeSlots
{
    /**
     * @param array<string, array<string, string>> $values key => locale => text
     * @param array<string, string>                $areas  key => area
     *
     * @return list<Slot>
     */
    public static function make(array &$values, array $areas): array
    {
        $slots = [];

        foreach ($areas as $key => $area) {
            $slots[] = new Slot(
                $area,
                $key,
                'Label ' . $key,
                static function (string $locale) use (&$values, $key): string {
                    return $values[$key][$locale] ?? '';
                },
                static function (string $locale, string $value) use (&$values, $key): void {
                    $values[$key][$locale] = $value;
                },
            );
        }

        return $slots;
    }
}
