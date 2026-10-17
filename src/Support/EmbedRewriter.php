<?php

namespace CookieManagerKit\Support;

/**
 * Blocks iframes that belong to a known service until the visitor consents to its category.
 *
 * It works on the ORIGINAL bytes and splices only the changed spans; everything else stays byte-identical. Splicing
 * beats re-serialising a parsed tree because no parser agrees with every browser on every odd page, and a
 * re-serialised page moves, re-encodes and wraps markup the visitor never asked to change (for example a
 * `<head><noscript><img>` ending up in `<body>`). Consent compliance must not depend on knowing the tree.
 *
 * Layer 1 (blocking, universal): every `<iframe` followed by whitespace, `/` or `>` anywhere in the bytes (scripts,
 * comments, attribute values, noscript, templates...) is read with the HTML start-tag grammar. If its `src`
 * (character references decoded, URL normalised like a browser) names a known service, each `src` attribute name is
 * renamed to `data-cmk-src` and ` data-cmk-embed=CATEGORY` is inserted after the tag name. No quote characters are
 * inserted, so this is safe inside a quoted attribute or a JavaScript string. A browser then has an iframe without a
 * `src`, and the loader restores it on consent. Existing `data-cmk-*` attributes on such a tag are disarmed.
 *
 * Layer 2 (placeholder, only when sure): a small tokenizer-lite pass identifies which of those iframes are real
 * start tags in normal markup (not inside comments, raw text, bogus comments or other tags). Only those also get a
 * placeholder (message, accept button, preferences button) inserted before them and a closing `</span>` after their
 * end tag.
 *
 * Fails closed: on any error, or if the result still contains a live matched iframe, every iframe start tag has its
 * `src` renamed instead.
 *
 * Known limits: iframes inside `srcdoc` attribute content, relative `src` values resolved against `<base href>`, and
 * `<object>`, `<embed>` and `<frame>` are out of scope.
 */
final class EmbedRewriter
{
    private const OCCURRENCE = '#<iframe(?=[\t\n\f\r />])#i';
    private const RAW_TEXT = ['script', 'style', 'textarea', 'title', 'xmp', 'noembed', 'noframes', 'iframe'];
    private const SPACE = "\t\n\f\r ";

    /**
     * Layer 1: every iframe start tag that is complete and names a known service.
     *
     * @param list<array{name: string, category: string, categoryTitle: string, domains: list<string>}> $services
     *
     * @return list<array{at: int, nameEnd: int, tagEnd: int, attrs: list<array{name: string, at: int, value: string}>, service: array{name: string, category: string, categoryTitle: string, domains: list<string>}, url: string, values: array<string, string>}>
     */
    private static function occurrences(string $html, array $services, int &$budget): array
    {
        if (preg_match_all(self::OCCURRENCE, $html, $matches, PREG_OFFSET_CAPTURE) === false) {
            throw new \RuntimeException('Searching for iframes failed.');
        }

        $found = [];

        foreach ($matches[0] as [, $at]) {
            $tag = self::tag($html, $at + 1, $budget);

            if ($tag === null) {
                continue;
            }

            $values = [];

            foreach ($tag['attrs'] as $attribute) {
                $values[$attribute['name']] ??= $attribute['value'];
            }

            if (isset($values['srcdoc']) || !isset($values['src'])) {
                continue;
            }

            $parsed = self::parse(self::decode($values['src']));

            if ($parsed === null) {
                continue;
            }

            $service = $parsed['host'] !== null ? self::match($parsed['url'], $services) : self::named($parsed['authority'], $services);

            if ($service === null || !Text::isHandle($service['category'])) {
                continue;
            }

            $found[] = [
                'at' => $at,
                'nameEnd' => $at + 7,
                'tagEnd' => $tag['end'],
                'attrs' => $tag['attrs'],
                'service' => $service,
                'url' => $parsed['url'],
                'values' => $values,
            ];
        }

        return $found;
    }

    /**
     * Reads a start or end tag with the WHATWG tokenizer rules for names and attributes. Quotes only delimit a value
     * where a value starts; elsewhere they are literal. Returns null when the input ends before `>` (a browser never
     * emits such a tag).
     *
     * @return array{end: int, attrs: list<array{name: string, at: int, value: string}>}|null
     */
    private static function tag(string $html, int $from, int &$budget): ?array
    {
        $n = strlen($html);
        $i = $from + strcspn($html, self::SPACE . '/>', $from);
        $attrs = [];

        while (true) {
            $budget -= 1;
            $i += strspn($html, self::SPACE . '/', $i);

            if ($i >= $n) {
                return null;
            }

            if ($html[$i] === '>') {
                return ['end' => $i, 'attrs' => $attrs];
            }

            $start = $i;
            $i += 1 + strcspn($html, self::SPACE . '/>=', $i + 1);
            $name = strtolower(substr($html, $start, $i - $start));
            $j = $i + strspn($html, self::SPACE, $i);
            $value = '';

            if ($j < $n && $html[$j] === '=') {
                $j += 1 + strspn($html, self::SPACE, $j + 1);

                if ($j >= $n) {
                    return null;
                }

                if ($html[$j] === '"' || $html[$j] === "'") {
                    $close = strpos($html, $html[$j], $j + 1);

                    if ($close === false) {
                        $budget -= $n - $j;

                        if ($budget < 0) {
                            throw new \RuntimeException('The page is too tangled to scan safely.');
                        }

                        return null;
                    }

                    $value = substr($html, $j + 1, $close - $j - 1);
                    $j = $close + 1;
                } elseif ($html[$j] !== '>') {
                    $length = strcspn($html, self::SPACE . '>', $j);
                    $value = substr($html, $j, $length);
                    $j += $length;
                }
            }

            $attrs[] = ['name' => $name, 'at' => $start, 'value' => $value];
            $budget -= $j - $start;

            if ($budget < 0) {
                throw new \RuntimeException('The page is too tangled to scan safely.');
            }

            $i = $j;
        }
    }

    /**
     * Layer 2: the iframe start tags that are real markup, mapped to the offset of their `</iframe` closer.
     *
     * @return array<int, int>
     */
    private static function real(string $html, int &$budget): array
    {
        $n = strlen($html);
        $i = 0;
        $real = [];

        while (($i = strpos($html, '<', $i)) !== false) {
            $c = $html[$i + 1] ?? '';

            if ($c === '!') {
                if (substr_compare($html, '<!--', $i, 4) === 0) {
                    $s = $i + 4;

                    if (($html[$s] ?? '') === '>') {
                        $i = $s + 1;
                    } elseif (substr_compare($html, '->', $s, 2) === 0) {
                        $i = $s + 2;
                    } elseif (preg_match('/--!?>/', $html, $m, PREG_OFFSET_CAPTURE, $s) === 1) {
                        $i = $m[0][1] + strlen($m[0][0]);
                    } else {
                        break;
                    }

                    continue;
                }

                $close = strpos($html, '>', $i);
            } elseif ($c === '?') {
                $close = strpos($html, '>', $i);
            } elseif ($c === '/') {
                $d = $html[$i + 2] ?? '';

                if ($d === '>') {
                    $i += 3;

                    continue;
                }

                if (ctype_alpha($d)) {
                    $tag = self::tag($html, $i + 2, $budget);
                    $close = $tag === null ? false : $tag['end'];
                } else {
                    $close = strpos($html, '>', $i);
                }
            } elseif (ctype_alpha($c)) {
                $tag = self::tag($html, $i + 1, $budget);

                if ($tag === null) {
                    break;
                }

                $name = strtolower(substr($html, $i + 1, strcspn($html, self::SPACE . '/>', $i + 1)));
                $close = $tag['end'];

                if ($name === 'plaintext') {
                    break;
                }

                if (in_array($name, self::RAW_TEXT, true)) {
                    if (preg_match('#</' . $name . '(?=[\t\n\f\r />])#i', $html, $m, PREG_OFFSET_CAPTURE, $close + 1) !== 1) {
                        if ($name === 'iframe') {
                            // Never closed: still real markup, but no wrapper can be placed.
                            $real[$i] = -1;
                        }

                        break;
                    }

                    if ($name === 'iframe') {
                        $real[$i] = $m[0][1];
                    }

                    $i = $m[0][1];

                    continue;
                }
            } else {
                $i += 1;

                continue;
            }

            if ($close === false) {
                break;
            }

            $i = $close + 1;
        }

        return $real;
    }

    /**
     * @param list<array{at: int, nameEnd: int, tagEnd: int, attrs: list<array{name: string, at: int, value: string}>, service: array{name: string, category: string, categoryTitle: string, domains: list<string>}, url: string, values: array<string, string>}> $found
     * @param array{message: string, accept: string, customise: string}                                                                                                                                                                                           $text
     *
     * @return list<array{0: int, 1: int, 2: int, 3: string}> offset, order, bytes to delete, text to insert
     */
    private static function edits(string $html, array $found, array $text, int &$budget): array
    {
        $real = self::real($html, $budget);
        $edits = [];

        foreach ($found as $f) {
            $category = $f['service']['category'];
            $next = $html[$f['nameEnd']] ?? '';
            $edits[$f['nameEnd'] . ':i'] = [$f['nameEnd'], 1, 0, ' data-cmk-embed=' . $category . ($next === '/' ? ' ' : '')];

            foreach ($f['attrs'] as $attribute) {
                if ($attribute['name'] === 'src') {
                    $edits[$attribute['at'] . ':r'] = [$attribute['at'], 0, 3, 'data-cmk-src'];
                } elseif (str_starts_with($attribute['name'], 'data-cmk-')) {
                    $edits[($attribute['at'] + 8) . ':r'] = [$attribute['at'] + 8, 0, 1, '_'];
                }
            }

            $closer = $real[$f['at']] ?? -1;

            if ($closer < 0) {
                continue;
            }

            $end = self::tag($html, $closer + 2, $budget);

            if ($end === null) {
                continue;
            }

            $edits[$f['at'] . ':p'] = [$f['at'], 0, 0, self::placeholder($f['values'], $f['service'], $text)];
            $edits[($end['end'] + 1) . ':c'] = [$end['end'] + 1, 0, 0, '</span>'];
        }

        return array_values($edits);
    }

    /**
     * @param list<array{0: int, 1: int, 2: int, 3: string}> $edits
     */
    private static function splice(string $html, array $edits): string
    {
        usort($edits, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $out = '';
        $cursor = 0;

        foreach ($edits as [$offset, , $delete, $insert]) {
            if ($offset < $cursor) {
                continue;
            }

            $out .= substr($html, $cursor, $offset - $cursor) . $insert;
            $cursor = $offset + $delete;
        }

        return $out . substr($html, $cursor);
    }

    /**
     * @param array<string, string>                                                               $values
     * @param array{name: string, category: string, categoryTitle: string, domains: list<string>} $service
     * @param array{message: string, accept: string, customise: string}                           $text
     */
    private static function placeholder(array $values, array $service, array $text): string
    {
        $replace = ['{service}' => $service['name'], '{category}' => $service['categoryTitle']];
        $category = Output::e($service['category']);
        $style = '';

        if (preg_match('/^\d{1,4}\z/', trim(self::decode($values['width'] ?? ''))) === 1) {
            $style .= 'width:' . (int) trim(self::decode($values['width'])) . 'px;';
        }

        if (preg_match('/^\d{1,4}\z/', trim(self::decode($values['height'] ?? ''))) === 1) {
            $style .= 'min-height:' . (int) trim(self::decode($values['height'])) . 'px;';
        }

        return '<span class="cmk-embed"' . ($style === '' ? '' : ' style="' . $style . '"') . '>'
            . '<span class="cmk-embed__box" data-cmk-embed-placeholder>'
            . '<span class="cmk-embed__message">' . Output::e(strtr($text['message'], $replace)) . '</span>'
            . '<span class="cmk-embed__actions">'
            . '<button type="button" class="cmk-embed__accept" data-cmk-embed-accept="' . $category . '">' . Output::e(strtr($text['accept'], $replace)) . '</button> '
            . '<button type="button" class="cmk-embed__prefs" data-cmk-open>' . Output::e($text['customise']) . '</button>'
            . '</span></span>';
    }

    /**
     * Decodes character references the way an attribute value is read: numeric ones with or without the semicolon.
     */
    private static function decode(string $value): string
    {
        if (!str_contains($value, '&')) {
            return $value;
        }

        $value = preg_replace_callback('/&#(?:[xX]([0-9a-fA-F]{1,8})|([0-9]{1,10}));?/', static function (array $m): string {
            $code = $m[1] !== '' ? (int) hexdec($m[1]) : (int) $m[2];

            return $code > 0 && $code <= 0x10FFFF && ($code < 0xD800 || $code > 0xDFFF) ? (string) mb_chr($code, 'UTF-8') : "\u{FFFD}";
        }, $value) ?? '';

        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Reads a URL the way a browser does before a request: strips surrounding controls and every tab and newline,
     * turns backslashes into slashes, treats a scheme-relative start as https, and ignores userinfo and port. Only
     * http(s) is returned. `host` is null when the host cannot be validated; `authority` is then the raw section.
     *
     * @return array{url: string, authority: string, host: string|null}|null
     */
    private static function parse(string $url): ?array
    {
        $url = trim($url, "\x00..\x20");
        $url = str_replace(["\t", "\r", "\n", '\\'], ['', '', '', '/'], $url);

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        if (preg_match('#^(https?):/*+(.*+)\z#is', $url, $m) !== 1) {
            return null;
        }

        $scheme = strtolower($m[1]);
        $rest = $m[2];
        $end = strcspn($rest, '/?#');
        $authority = substr($rest, 0, $end);
        $afterAuthority = substr($rest, $end);
        $at = strrpos($authority, '@');
        $hostPart = $at === false ? $authority : substr($authority, $at + 1);

        if (str_starts_with($hostPart, '[')) {
            $host = null;
        } else {
            $hostPart = (string) preg_replace('/:\d*+\z/', '', $hostPart);
            $host = self::cleanHost($hostPart);
        }

        return [
            'url' => $scheme . '://' . $rest,
            'authority' => $host === null ? $authority . $afterAuthority : $authority,
            'host' => $host,
        ];
    }

    private static function cleanHost(string $host): ?string
    {
        $host = rtrim(strtolower(rawurldecode($host)), '.');

        if ($host === '') {
            return null;
        }

        if (preg_match('/^[a-z0-9.-]+\z/', $host) !== 1) {
            if (!function_exists('idn_to_ascii')) {
                return null;
            }

            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46);
            $host = is_string($ascii) ? rtrim(strtolower($ascii), '.') : '';
        }

        return preg_match('/^[a-z0-9.-]+\z/', $host) === 1 ? $host : null;
    }

    /**
     * Backstop for an http(s) URL whose host could not be validated: the first service whose domain appears in it.
     *
     * @param list<array{name: string, category: string, categoryTitle: string, domains: list<string>}> $services
     *
     * @return array{name: string, category: string, categoryTitle: string, domains: list<string>}|null
     */
    private static function named(string $text, array $services): ?array
    {
        $text = strtolower(rawurldecode($text));

        foreach ($services as $service) {
            foreach ($service['domains'] as $domain) {
                $base = self::base($domain);

                if ($base !== '' && str_contains($text, $base)) {
                    return $service;
                }
            }
        }

        return null;
    }

    private static function base(string $domain): string
    {
        return strtolower(str_starts_with($domain, '*.') ? substr($domain, 2) : $domain);
    }

    private static function report(callable $onError, \Throwable $error): void
    {
        try {
            $onError($error);
        } catch (\Throwable) {
        }
    }

    /**
     * @param list<array{name: string, category: string, categoryTitle: string, domains: list<string>}> $services
     * @param array{message: string, accept: string, customise: string}                                 $text
     * @param callable(\Throwable): void                                                                $onError
     */
    public static function rewrite(string $html, array $services, array $text, callable $onError): string
    {
        if ($services === [] || stripos($html, '<iframe') === false) {
            return $html;
        }

        try {
            $budget = 4 * strlen($html) + 2_000_000;
            $found = self::occurrences($html, $services, $budget);

            if ($found === []) {
                return $html;
            }

            $out = self::splice($html, self::edits($html, $found, $text, $budget));

            if (self::audit($out, $services) !== []) {
                throw new \RuntimeException('A blocked iframe is still live after rewriting.');
            }

            return $out;
        } catch (\Throwable $e) {
            self::report($onError, $e);

            return self::failClosed($html);
        }
    }

    /**
     * Renames `src` to `data-cmk-src` in every iframe start tag, whether or not it names a known service. Used when
     * the page cannot be rewritten precisely. The extent of a tag is read both quote-aware and plain, so a quote
     * the browser reads differently cannot hide a `src`.
     */
    public static function failClosed(string $html): string
    {
        $rename = static fn(array $found): string => $found[1] . (preg_replace('#(?<![A-Za-z0-9_:.-])src(?=[\t\n\f\r /]*=)#i', 'data-cmk-src', $found[2]) ?? '');

        $out = preg_replace_callback('#(<iframe)(?=[\t\n\f\r />])((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>?)#i', $rename, $html);
        $out = $out === null ? null : preg_replace_callback('#(<iframe)(?=[\t\n\f\r />])([^>]*+>?)#i', $rename, $out);

        return $out ?? '';
    }

    /**
     * The iframe occurrences whose `src` still names a known service. Empty when nothing is live.
     *
     * @param list<array{name: string, category: string, categoryTitle: string, domains: list<string>}> $services
     *
     * @return list<int> byte offsets
     */
    public static function audit(string $html, array $services): array
    {
        $budget = 4 * strlen($html) + 2_000_000;

        return array_column(self::occurrences($html, $services, $budget), 'at');
    }

    /**
     * The lower-case ASCII host of an http(s) URL, read the way a browser reads it, or null.
     */
    public static function host(string $url): ?string
    {
        return self::parse($url)['host'] ?? null;
    }

    /**
     * @param list<array{name: string, category: string, categoryTitle: string, domains: list<string>}> $services
     *
     * @return array{name: string, category: string, categoryTitle: string, domains: list<string>}|null
     */
    public static function match(string $url, array $services): ?array
    {
        $host = self::host($url);

        if ($host === null) {
            return null;
        }

        foreach ($services as $service) {
            foreach ($service['domains'] as $domain) {
                $base = self::base($domain);

                if ($base !== '' && ($host === $base || str_ends_with($host, '.' . $base))) {
                    return $service;
                }
            }
        }

        return null;
    }
}
