<?php

namespace CookieManagerKit\Translation;

use CookieManagerKit\Support\Text;
use CookieManagerKit\Support\CsvCell;

/**
 * Pure: the translation CSV. One file is for one target locale. Import is all or nothing: the file is parsed and
 * every key checked before a single value is written.
 */
final class TranslationCsv
{
    private const HEADER = ['key', 'area', 'default_text', 'translation'];
    private const LOCALE_LINE = '# locale=';
    public const MAX_BYTES = 524288;
    public const MAX_ROWS = 5000;
    public const MAX_CELL_BYTES = 10000;

    /**
     * @param list<Slot> $slots
     */
    public static function export(array $slots, string $locale, string $default): string
    {
        $out = "\xEF\xBB\xBF" . CsvCell::row([self::LOCALE_LINE . $locale]) . CsvCell::row(self::HEADER);

        foreach (TranslationReport::missing($slots, $locale, $default) as $slot) {
            $out .= CsvCell::row([$slot->key, $slot->area, $slot->read($default), '']);
        }

        return $out;
    }

    /**
     * @return list<array{key: string, value: string}>
     *
     * @throws \InvalidArgumentException
     */
    public static function parse(string $csv, string $locale): array
    {
        if (strlen($csv) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('The file is too large.');
        }

        $handle = fopen('php://memory', 'r+');

        if ($handle === false) {
            throw new \InvalidArgumentException('The file could not be read.');
        }

        try {
            fwrite($handle, str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv);
            rewind($handle);

            $first = fgetcsv($handle, 0, ',', '"', '');

            if (!is_array($first) || $first !== [self::LOCALE_LINE . $locale]) {
                throw new \InvalidArgumentException('The file is not for this language.');
            }

            if (fgetcsv($handle, 0, ',', '"', '') !== self::HEADER) {
                throw new \InvalidArgumentException('The file does not have the expected columns.');
            }

            $rows = [];
            $seen = [];

            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($cells === [null]) {
                    continue;
                }

                if (count($cells) < 4) {
                    throw new \InvalidArgumentException('A row has too few columns.');
                }

                foreach ($cells as $cell) {
                    if (strlen((string) $cell) > self::MAX_CELL_BYTES) {
                        throw new \InvalidArgumentException('A cell is too long.');
                    }
                }

                $key = (string) $cells[0];

                if ($key === '' || isset($seen[$key])) {
                    throw new \InvalidArgumentException('A row has an empty or repeated key.');
                }

                if (count($rows) >= self::MAX_ROWS) {
                    throw new \InvalidArgumentException('The file has too many rows.');
                }

                $seen[$key] = true;
                $rows[] = ['key' => $key, 'value' => (string) $cells[3]];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param list<Slot>                              $slots
     * @param list<array{key: string, value: string}> $rows
     *
     * @return array{applied: int, skipped: int}
     *
     * @throws \InvalidArgumentException
     */
    public static function apply(array $slots, array $rows, string $locale, string $default): array
    {
        if ($locale === $default) {
            throw new \InvalidArgumentException('The default language cannot be imported.');
        }

        $byKey = [];

        foreach ($slots as $slot) {
            $byKey[$slot->key] = $slot;
        }

        foreach ($rows as $row) {
            if (!isset($byKey[$row['key']])) {
                throw new \InvalidArgumentException('The file contains a field that does not exist.');
            }
        }

        $applied = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            // Text::plain mirrors the slots' own sanitising: a value of only tags or control characters writes nothing.
            if (Text::plain($row['value']) === '') {
                $skipped++;

                continue;
            }

            $byKey[$row['key']]->write($locale, $row['value']);
            $applied++;
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }
}
