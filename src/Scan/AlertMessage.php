<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Support\Output;

/**
 * The alert text. Names and hosts came from the public internet, so each one is flattened to a single line (no control,
 * format or line-separator characters, so nothing can add a header or a fake paragraph) and capped.
 *
 * There is deliberately no HTML part: the mail is sent as text/plain only, where markup is inert, so there is nothing
 * to HTML-encode and no second rendering of the same hostile value to keep in step. If an HTML part is ever added it
 * must be built separately from these lines with every dynamic value passed through Output::e().
 */
final class AlertMessage
{
    private const MAX_LINE = 80;
    private const MAX_HOST = 253;

    private static function line(string $value, int $max = self::MAX_LINE): string
    {
        $value = mb_scrub($value, 'UTF-8');
        $value = preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}\s]+/u', ' ', $value) ?? '';

        return mb_substr(trim($value), 0, $max, 'UTF-8');
    }

    /**
     * @return array{subject: string, body: string}
     */
    public static function build(string $siteHost, IngestSummary $summary, bool $runtimeStale, int $staleDays, string $findingsUrl): array
    {
        $host = substr(preg_replace('/[^A-Za-z0-9.\-]/', '', $siteHost) ?? '', 0, self::MAX_HOST);
        $subject = 'Cookie scan: ' . $summary->new . ' new, ' . $summary->changed . ' changed on ' . $host;

        $lines = [
            'A scan of ' . $host . ' found changes.',
            '',
            $summary->new . ' new, ' . $summary->changed . ' changed.',
            $summary->unclassified . ' findings still need a category.',
        ];

        if ($summary->newItems !== []) {
            $lines[] = '';
            $lines[] = 'New findings:';

            foreach ($summary->newItems as $item) {
                $lines[] = '- ' . self::line($item['kind'], 16) . ': ' . self::line($item['name']) . ' (' . self::line($item['host']) . ')';
            }
        }

        if ($runtimeStale) {
            $lines[] = '';
            $lines[] = 'No runtime scan has run in the last ' . $staleDays . ' days, so cookies and storage set by scripts may be missing. Start scan mode in the dashboard and browse the site.';
        }

        $url = Output::safeUrl($findingsUrl);

        if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
            $lines[] = '';
            $lines[] = 'Review them: ' . self::line($url, 300);
        }

        return ['subject' => $subject, 'body' => implode("\n", $lines) . "\n"];
    }
}
