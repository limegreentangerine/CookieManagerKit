<?php

namespace CookieManagerKit\Support;

/**
 * Makes a pasted tracking snippet (Concrete's SEO tracking code) inert until its category is consented.
 * <noscript> blocks are removed: a visitor without JavaScript cannot consent, so their pixel must not load.
 * Each <script> open tag is parsed like a browser would and rebuilt from its attributes, never patched in place,
 * so that no duplicate or oddly delimited attribute can win over ours.
 */
final class TrackingCode
{
    private const WHITESPACE = " \t\n\f\r";

    public static function makeInert(string $code, string $category): string
    {
        if (!Text::isHandle($category)) {
            return '';
        }

        $code = self::removeNoscript($code);
        $output = '';
        $offset = 0;

        while (preg_match('#<script(?=[ \t\n\f\r/>])#i', $code, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $tag = self::parseOpenTag($code, $start + 7);

            if ($tag === null) {
                return '';
            }

            [$attributes, $end] = $tag;
            $output .= substr($code, $offset, $start - $offset) . self::rebuild($attributes, $category);
            $offset = $end;
        }

        return $output . substr($code, $offset);
    }

    private static function removeNoscript(string $code): string
    {
        $output = '';
        $offset = 0;

        while (preg_match('#<noscript(?=[ \t\n\f\r/>])#i', $code, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $output .= substr($code, $offset, $start - $offset);

            if (preg_match('#</noscript#i', $code, $close, PREG_OFFSET_CAPTURE, $start) !== 1) {
                return $output; // never closed: fail closed and drop the rest
            }

            $gt = strpos($code, '>', $close[0][1]);

            if ($gt === false) {
                return $output;
            }

            $offset = $gt + 1;
        }

        return $output . substr($code, $offset);
    }

    /**
     * Tokenises an open tag the way the HTML spec does, from just after "<script".
     *
     * @return array{0: list<array{0: string, 1: ?string}>, 1: int}|null attributes and the offset after ">", or null if the tag never ends
     */
    private static function parseOpenTag(string $html, int $pos): ?array
    {
        $length = strlen($html);
        $attributes = [];

        while (true) {
            $pos += strspn($html, self::WHITESPACE . '/', $pos);

            if ($pos >= $length) {
                return null;
            }

            if ($html[$pos] === '>') {
                return [$attributes, $pos + 1];
            }

            $nameStart = $pos;
            $pos++; // the first character is always part of the name, even "="
            $pos += strcspn($html, self::WHITESPACE . '/>=', $pos);
            $name = substr($html, $nameStart, $pos - $nameStart);
            $value = null;

            $pos += strspn($html, self::WHITESPACE, $pos);

            if ($pos < $length && $html[$pos] === '=') {
                $pos++;
                $pos += strspn($html, self::WHITESPACE, $pos);

                if ($pos >= $length) {
                    return null;
                }

                if ($html[$pos] === '"' || $html[$pos] === "'") {
                    $close = strpos($html, $html[$pos], $pos + 1);

                    if ($close === false) {
                        return null;
                    }

                    $value = substr($html, $pos + 1, $close - $pos - 1);
                    $pos = $close + 1;
                } else {
                    $valueStart = $pos;
                    $pos += strcspn($html, self::WHITESPACE . '>', $pos);
                    $value = substr($html, $valueStart, $pos - $valueStart);
                }
            }

            $attributes[] = [$name, $value];
        }
    }

    /**
     * @param list<array{0: string, 1: ?string}> $attributes
     */
    private static function rebuild(array $attributes, string $category): string
    {
        $tag = '<script type="text/plain" data-cmk-category="' . $category . '"';
        $type = null;
        $seen = [];
        $rest = '';

        foreach ($attributes as [$name, $value]) {
            $lower = strtolower($name);

            if ($lower === 'type') {
                $type ??= (string) $value;

                continue;
            }

            if (str_starts_with($lower, 'data-cmk-') || isset($seen[$lower])) {
                continue;
            }

            $seen[$lower] = true;

            if (preg_match('/^[A-Za-z_:][A-Za-z0-9_:.\-]*\z/', $name) !== 1) {
                continue;
            }

            $rest .= $value === null ? ' ' . $name : ' ' . $name . '="' . str_replace('"', '&quot;', $value) . '"';
        }

        if (
            $type !== null
            && preg_match('#^[a-z0-9/+.\-]{1,64}\z#i', $type) === 1
            && !in_array(strtolower($type), ['text/javascript', 'application/javascript', 'text/plain'], true)
        ) {
            $tag .= ' data-cmk-type="' . $type . '"';
        }

        return $tag . $rest . '>';
    }
}
