<?php

namespace CookieManagerKit\Support;

/**
 * CSV output that spreadsheets cannot turn into formulas. A cell that starts with = + - @ tab or CR is prefixed with
 * a single quote; every field is quoted and its quotes doubled.
 */
final class CsvCell
{
    public static function neutralise(string $cell): string
    {
        return $cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false ? "'" . $cell : $cell;
    }

    /**
     * @param list<scalar> $cells
     */
    public static function row(array $cells): string
    {
        $out = [];

        foreach ($cells as $cell) {
            $out[] = '"' . str_replace('"', '""', self::neutralise((string) $cell)) . '"';
        }

        return implode(',', $out) . "\r\n";
    }
}
