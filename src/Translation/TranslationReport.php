<?php

namespace CookieManagerKit\Translation;

/**
 * Pure: what is translated, what is missing, and filling the gaps from the default locale. A field only counts
 * when the default locale has text for it (there is nothing to translate otherwise).
 */
final class TranslationReport
{
    public const AREAS = ['banner', 'category', 'service', 'cookie'];

    /**
     * @param list<Slot>   $slots
     * @param list<string> $locales
     *
     * @return array<string, array<string, array{filled: int, total: int}>> area => locale => counts
     */
    public static function matrix(array $slots, array $locales, string $default): array
    {
        $matrix = [];

        foreach (self::AREAS as $area) {
            foreach ($locales as $locale) {
                $matrix[$area][$locale] = ['filled' => 0, 'total' => 0];
            }
        }

        foreach ($slots as $slot) {
            if (!isset($matrix[$slot->area]) || $slot->read($default) === '') {
                continue;
            }

            foreach ($locales as $locale) {
                $matrix[$slot->area][$locale]['total']++;

                if ($slot->read($locale) !== '') {
                    $matrix[$slot->area][$locale]['filled']++;
                }
            }
        }

        return $matrix;
    }

    /**
     * @param list<Slot> $slots
     *
     * @return list<Slot>
     */
    public static function missing(array $slots, string $locale, string $default): array
    {
        return array_values(array_filter(
            $slots,
            static fn(Slot $slot): bool => $locale !== $default && $slot->read($default) !== '' && $slot->read($locale) === '',
        ));
    }

    /**
     * Fills empty fields of one area in $locale with the default locale's text. Never overwrites, never writes to
     * the default locale.
     *
     * @param list<Slot> $slots
     */
    public static function copyFromDefault(array $slots, string $area, string $locale, string $default): int
    {
        if (!in_array($area, self::AREAS, true)) {
            return 0;
        }

        $count = 0;

        foreach (self::missing($slots, $locale, $default) as $slot) {
            if ($slot->area === $area) {
                $slot->write($locale, $slot->read($default));
                $count++;
            }
        }

        return $count;
    }
}
