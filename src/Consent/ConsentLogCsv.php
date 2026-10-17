<?php

namespace CookieManagerKit\Consent;

use CookieManagerKit\Support\CsvCell;

final class ConsentLogCsv
{
    public const MAX_ROWS = 100000;

    public const HEADER = ['Date (UTC)', 'Consent ID', 'Policy version', 'Categories', 'Trigger', 'Locale', 'User agent family', 'Re-consent reason'];

    /**
     * @param list<array{createdAt: int, consentId: string, version: int, categories: list<string>, trigger: string, locale: string, userAgentFamily: string, reason: string}> $rows
     */
    public static function render(array $rows): string
    {
        $out = CsvCell::row(self::HEADER);

        foreach ($rows as $row) {
            $out .= CsvCell::row([
                gmdate('Y-m-d H:i:s', $row['createdAt']),
                $row['consentId'],
                $row['version'],
                implode(' ', $row['categories']),
                $row['trigger'],
                $row['locale'],
                $row['userAgentFamily'],
                $row['reason'],
            ]);
        }

        return $out;
    }
}
