<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Support\Text;

/**
 * Reads classification data. Two formats: the package's own CSV (the bundled baseline), and the Open Cookie
 * Database CSV that the optional refresh downloads. Both are validated row by row. The bundled format is strict (one
 * bad row rejects the file); the downloaded format tolerates a small share of unusable rows but rejects a file that
 * is too small, too large or mostly unusable, because a truncated or hostile download must never replace good data.
 */
final class ClassificationFile
{
    private const HEADER = ['kind', 'pattern', 'category', 'vendor', 'description', 'duration'];
    private const KINDS = ['cookie', 'storage', 'host'];
    private const OCD_CATEGORIES = [
        'functional' => 'preferences',
        'personalization' => 'preferences',
        'analytics' => 'analytics',
        'marketing' => 'marketing',
        'security' => 'necessary',
        'necessary' => 'necessary',
    ];
    private const OCD_MIN_ROWS = 50;
    private const OCD_MAX_DROPPED = 0.25;
    public const MAX_BYTES = 5000000;
    public const MAX_ROWS = 20000;

    /**
     * @return list<array<int, string>>|null
     */
    private static function read(string $csv): ?array
    {
        if ($csv === '' || strlen($csv) > self::MAX_BYTES) {
            return null;
        }

        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $rows = [];

        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }

            $rows[] = array_map(static fn($cell): string => (string) $cell, $row);

            if (count($rows) > self::MAX_ROWS + 1) {
                fclose($stream);

                return null;
            }
        }

        fclose($stream);

        return $rows === [] ? null : $rows;
    }

    /**
     * A wildcard pattern must stay specific: at least three literal characters, and for hosts a literal part with two
     * or more labels whose registrable-looking label is at least three characters (so "*.com" and "*.co.uk" fail).
     * Exact names have no such floor ("fr" is a real cookie).
     */
    private static function specificEnough(string $kind, string $pattern): bool
    {
        if (!str_contains($pattern, '*')) {
            return true;
        }

        $literal = str_replace('*', '', $pattern);

        if (strlen($literal) < 3) {
            return false;
        }

        if ($kind === 'host') {
            $labels = array_values(array_filter(explode('.', $literal), static fn(string $label): bool => $label !== ''));

            return count($labels) >= 2 && strlen($labels[count($labels) - 2]) >= 3;
        }

        return true;
    }

    /**
     * @return array{kind: string, pattern: string, category: string, vendor: string, description: string, duration: string}|null
     */
    private static function row(string $kind, string $pattern, string $category, string $vendor, string $description, string $duration): ?array
    {
        $pattern = trim($pattern);

        if (!in_array($kind, self::KINDS, true)
            || preg_match('/^[A-Za-z0-9_.*:~%+\-]{1,128}\z/', $pattern) !== 1
            || substr_count($pattern, '*') > 3
            || !Text::isHandle($category)
            || !self::specificEnough($kind, $pattern)) {
            return null;
        }

        return [
            'kind' => $kind,
            'pattern' => $kind === 'host' ? strtolower($pattern) : $pattern,
            'category' => $category,
            'vendor' => Text::plain($vendor),
            'description' => Text::plain($description, 500),
            'duration' => Text::plain($duration, 64),
        ];
    }

    public static function baselinePath(): string
    {
        return dirname(__DIR__, 2) . '/data/classification-baseline.csv';
    }

    /**
     * @return list<array{kind: string, pattern: string, category: string, vendor: string, description: string, duration: string}>|null
     */
    public static function parseBundled(string $csv): ?array
    {
        if (!mb_check_encoding($csv, 'UTF-8')) {
            return null;
        }

        $rows = self::read($csv);

        if ($rows === null || array_map('strtolower', array_map('trim', array_shift($rows))) !== self::HEADER) {
            return null;
        }

        $out = [];
        $seen = [];

        foreach ($rows as $row) {
            if (count($row) !== count(self::HEADER)) {
                return null;
            }

            $entry = self::row($row[0], $row[1], $row[2], $row[3], $row[4], $row[5]);

            if ($entry === null) {
                return null;
            }

            $key = $entry['kind'] . "\0" . $entry['pattern'];

            if (isset($seen[$key])) {
                return null;
            }

            $seen[$key] = true;
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @return list<array{kind: string, pattern: string, category: string, vendor: string, description: string, duration: string}>|null
     */
    public static function parseOpenCookieDatabase(string $csv): ?array
    {
        $rows = self::read($csv);

        if ($rows === null) {
            return null;
        }

        $header = array_map(static fn(string $cell): string => strtolower(trim($cell)), array_shift($rows));
        $at = [];

        foreach (['category', 'cookie / data key name', 'description', 'retention period', 'data controller', 'wildcard match'] as $column) {
            $position = array_search($column, $header, true);

            if ($position === false) {
                return null;
            }

            $at[$column] = $position;
        }

        $total = count($rows);
        $out = [];
        $dropped = 0;

        foreach ($rows as $row) {
            $category = self::OCD_CATEGORIES[strtolower(trim($row[$at['category']] ?? ''))] ?? null;
            $name = trim($row[$at['cookie / data key name']] ?? '');

            if ($category !== null && $name !== '' && trim($row[$at['wildcard match']] ?? '') === '1' && !str_ends_with($name, '*')) {
                $name .= '*';
            }

            $entry = $category === null ? null : self::row(
                'cookie',
                $name,
                $category,
                $row[$at['data controller']] ?? '',
                $row[$at['description']] ?? '',
                $row[$at['retention period']] ?? '',
            );

            if ($entry !== null && $entry['category'] === 'necessary' && str_contains($entry['pattern'], '*')) {
                $entry = null;
            }

            if ($entry === null) {
                $dropped++;

                continue;
            }

            $out[$entry['kind'] . "\0" . $entry['pattern']] ??= $entry;
        }

        if ($total < self::OCD_MIN_ROWS || $dropped > $total * self::OCD_MAX_DROPPED || $out === []) {
            return null;
        }

        return array_values($out);
    }
}
