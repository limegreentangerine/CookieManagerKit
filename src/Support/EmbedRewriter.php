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
 * Fails closed: on any error, on a page with more than 1000 `<iframe` or more than 4 MB, or if the result still
 * contains a live matched iframe (checked by a coarse second scan that shares no code with the matcher), every
 * `<iframe` gets a bare ` srcdoc` attribute inserted right after its name. The element stays an iframe (so its content
 * stays inert text), no quote is inserted, and browsers ignore `src` when `srcdoc` is present. A page is never blanked.
 *
 * Grammar commentary: tag() is the attribute grammar, real() is the markup-context reading and coarse() is the
 * deliberately cruder cross-check; a change to one must be checked against the other two. The coarse check only
 * extends past a `>` for a quote that opens an attribute value (never an apostrophe in text), only reads a `src` in
 * attribute-name position, only the first one (a browser uses the first), and treats any `srcdoc` as "loads nothing".
 *
 * Accepted limits: a page with more than 1000 `<iframe` or more than 4 MB is fully blocked with ` srcdoc` (unmatched
 * iframes too, no placeholders); the fail-closed path inserts that text into any context, including scripts and
 * comments; the server's ICU version can differ from a browser's IDNA tables, which is handled by guess() (a host that
 * fails validation is read with every character outside [a-z0-9.-] deleted and matched at a label boundary). A host
 * that fails validation and still holds a non-ASCII character the server cannot fold (unassigned in its ICU, or no
 * intl extension to fold with) cannot be read reliably, so the whole page is blocked with ` srcdoc`.
 *
 * Call rewrite() only on public front-end page output, never on edit-mode or dashboard output.
 *
 * Known limits: iframes inside `srcdoc` attribute content, relative `src` values resolved against `<base href>`, and
 * `<object>`, `<embed>` and `<frame>` are out of scope.
 */
final class EmbedRewriter
{
    private const OCCURRENCE = '#<iframe(?=[\t\n\f\r />])#i';
    private const RAW_TEXT = ['script', 'style', 'textarea', 'title', 'xmp', 'noembed', 'noframes', 'iframe', 'noscript'];
    private const LEGACY = ['AElig', 'AMP', 'Aacute', 'Acirc', 'Agrave', 'Aring', 'Atilde', 'Auml', 'COPY', 'Ccedil', 'ETH', 'Eacute', 'Ecirc', 'Egrave', 'Euml', 'GT', 'Iacute', 'Icirc', 'Igrave', 'Iuml', 'LT', 'Ntilde', 'Oacute', 'Ocirc', 'Ograve', 'Oslash', 'Otilde', 'Ouml', 'QUOT', 'REG', 'THORN', 'Uacute', 'Ucirc', 'Ugrave', 'Uuml', 'Yacute', 'aacute', 'acirc', 'acute', 'aelig', 'agrave', 'amp', 'aring', 'atilde', 'auml', 'brvbar', 'ccedil', 'cedil', 'cent', 'copy', 'curren', 'deg', 'divide', 'eacute', 'ecirc', 'egrave', 'eth', 'euml', 'frac12', 'frac14', 'frac34', 'gt', 'iacute', 'icirc', 'iexcl', 'igrave', 'iquest', 'iuml', 'laquo', 'lt', 'macr', 'micro', 'middot', 'nbsp', 'not', 'ntilde', 'oacute', 'ocirc', 'ograve', 'ordf', 'ordm', 'oslash', 'otilde', 'ouml', 'para', 'plusmn', 'pound', 'quot', 'raquo', 'reg', 'sect', 'shy', 'sup1', 'sup2', 'sup3', 'szlig', 'thorn', 'times', 'uacute', 'ucirc', 'ugrave', 'uml', 'uuml', 'yacute', 'yen', 'yuml'];
    private const SPACE = "\t\n\f\r ";

    /** Test seam: simulates a server without the intl extension. */
    private static bool $withoutIntl = false;

    /**
     * @return array{budget: int, q: array<string, array{0: int, 1: int|false}>, gt: int|false, memo: array<int, array{0: int|null, 1: list<array{name: string, at: int, value: string}>}>}
     */
    private static function context(string $html, int $perByte, int $extra = 2_000_000): array
    {
        return ['budget' => $perByte * strlen($html) + $extra, 'q' => [], 'gt' => strrpos($html, '>'), 'memo' => []];
    }

    private static function delimited(string $html, int $at): bool
    {
        $c = $html[$at] ?? '';

        return $c !== '' && strpbrk($c, "\t\n\f\r />") !== false;
    }

    /**
     * Throws when a PCRE call failed, so a failure never reads as "no match".
     */
    private static function ok(int|false|null $result): int
    {
        if ($result === false || $result === null || preg_last_error() !== PREG_NO_ERROR) {
            throw new \RuntimeException('Pattern matching failed: ' . preg_last_error_msg());
        }

        return $result;
    }

    private static function str(?string $result): string
    {
        if ($result === null || preg_last_error() !== PREG_NO_ERROR) {
            throw new \RuntimeException('Pattern matching failed: ' . preg_last_error_msg());
        }

        return $result;
    }

    /**
     * Layer 1: every iframe start tag that is complete and names a known service.
     *
     * @param list<array{name: string, category: string, categoryTitle: string, domains: list<string>}>                                                                                    $services
     * @param array{budget: int, q: array<string, array{0: int, 1: int|false}>, gt: int|false, memo: array<int, array{0: int|null, 1: list<array{name: string, at: int, value: string}>}>} $ctx
     *
     * @return list<array{at: int, nameEnd: int, attrs: list<array{name: string, at: int, value: string}>, service: array{name: string, category: string, categoryTitle: string, domains: list<string>}, values: array<string, string>}>
     */
    private static function occurrences(string $html, array $services, array &$ctx): array
    {
        self::ok(preg_match_all(self::OCCURRENCE, $html, $matches, PREG_OFFSET_CAPTURE));

        $found = [];

        foreach ($matches[0] as [, $at]) {
            $tag = self::tag($html, $at + 1, $ctx);

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

            $service = $parsed['host'] !== null ? self::matchHost($parsed['host'], $services) : ($parsed['guess'] !== null ? self::matchHost($parsed['guess'], $services) : self::named($parsed['authority'], $services));

            if ($service === null || !Text::isHandle($service['category'])) {
                continue;
            }

            $found[] = [
                'at' => $at,
                'nameEnd' => $at + 7,
                'attrs' => $tag['attrs'],
                'service' => $service,
                'values' => $values,
            ];
        }

        return $found;
    }

    /**
     * The next occurrence of a quote character, remembering the answer so an unclosed quote is searched for once.
     *
     * @param array{budget: int, q: array<string, array{0: int, 1: int|false}>, gt: int|false, memo: array<int, array{0: int|null, 1: list<array{name: string, at: int, value: string}>}>} $ctx
     */
    private static function quote(string $html, string $quote, int $from, array &$ctx): int|false
    {
        $cached = $ctx['q'][$quote] ?? null;

        if ($cached !== null && $cached[0] <= $from && ($cached[1] === false || $cached[1] >= $from)) {
            return $cached[1];
        }

        $found = strpos($html, $quote, $from);
        $ctx['q'][$quote] = [$from, $found];

        return $found;
    }

    /**
     * Reads a start or end tag with the WHATWG tokenizer rules for names and attributes. Quotes only delimit a value
     * where a value starts; elsewhere they are literal. Returns null when the input ends before `>` (a browser never
     * emits such a tag). Throws when the context's work budget is spent.
     *
     * @param array{budget: int, q: array<string, array{0: int, 1: int|false}>, gt: int|false, memo: array<int, array{0: int|null, 1: list<array{name: string, at: int, value: string}>}>} $ctx
     *
     * @return array{end: int, attrs: list<array{name: string, at: int, value: string}>}|null
     */
    private static function tag(string $html, int $from, array &$ctx): ?array
    {
        $n = strlen($html);

        if ($ctx['gt'] === false || $from > $ctx['gt']) {
            return null;
        }

        $i = $from + strcspn($html, self::SPACE . '/>', $from);
        $attrs = [];
        $visited = [];
        $end = null;

        while (true) {
            $i += strspn($html, self::SPACE . '/', $i);

            if (isset($ctx['memo'][$i])) {
                $end = $ctx['memo'][$i][0];
                $attrs = array_merge($attrs, $ctx['memo'][$i][1]);

                break;
            }

            $visited[] = [$i, count($attrs)];

            if ($i >= $n) {
                break;
            }

            if ($html[$i] === '>') {
                $end = $i;

                break;
            }

            $start = $i;
            $i += 1 + strcspn($html, self::SPACE . '/>=', $i + 1);
            $name = strtolower(substr($html, $start, $i - $start));
            $j = $i + strspn($html, self::SPACE, $i);
            $value = '';

            if ($j < $n && $html[$j] === '=') {
                $j += 1 + strspn($html, self::SPACE, $j + 1);

                if ($j >= $n) {
                    break;
                }

                if ($html[$j] === '"' || $html[$j] === "'") {
                    $close = self::quote($html, $html[$j], $j + 1, $ctx);

                    if ($close === false) {
                        break;
                    }

                    $value = substr($html, $j + 1, $close - $j - 1);
                    $j = $close + 1;
                } elseif ($html[$j] !== '>') {
                    $length = strcspn($html, self::SPACE . '>', $j);
                    $value = substr($html, $j, $length);
                    $j += $length;
                }
            }

            if (in_array($name, ['src', 'srcdoc', 'width', 'height'], true) || str_starts_with($name, 'data-cmk-')) {
                $attrs[] = ['name' => $name, 'at' => $start, 'value' => $value];
            }

            $ctx['budget'] -= 64 + $j - $start;

            if ($ctx['budget'] < 0 || count($attrs) > 256) {
                throw new \RuntimeException('The page is too tangled to scan safely.');
            }

            $i = $j;
        }

        // A long scan is remembered per attribute boundary, so overlapping scans of the same bytes cost O(1) each.
        if (count($visited) > 32) {
            foreach ($visited as [$boundary, $count]) {
                if (count($ctx['memo']) >= 20000) {
                    break;
                }

                if (count($attrs) - $count <= 16) {
                    $ctx['memo'][$boundary] = [$end, array_slice($attrs, $count)];
                }
            }
        }

        return $end === null ? null : ['end' => $end, 'attrs' => $attrs];
    }

    /**
     * Layer 2: the iframe start tags that are real markup, mapped to the offset of their `</iframe` closer. Stops
     * (so later iframes get no placeholder) wherever the reading could differ from a browser's.
     *
     * @param array{budget: int, q: array<string, array{0: int, 1: int|false}>, gt: int|false, memo: array<int, array{0: int|null, 1: list<array{name: string, at: int, value: string}>}>} $ctx
     * @param array<int, int>                                                                                                                                                              $real filled in as iframes are confirmed
     */
    private static function real(string $html, array &$ctx, array &$real): void
    {
        $i = 0;

        while (($i = strpos($html, '<', $i)) !== false) {
            $c = $html[$i + 1] ?? '';

            if ($c === '!') {
                if (substr_compare($html, '<!--', $i, 4) === 0) {
                    $s = $i + 4;

                    if (($html[$s] ?? '') === '>') {
                        $i = $s + 1;
                    } elseif (substr_compare($html, '->', $s, 2) === 0) {
                        $i = $s + 2;
                    } elseif (self::ok(preg_match('/--!?>/', $html, $m, PREG_OFFSET_CAPTURE, $s)) === 1) {
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
                    $tag = self::tag($html, $i + 2, $ctx);
                    $close = $tag === null ? false : $tag['end'];
                } else {
                    $close = strpos($html, '>', $i);
                }
            } elseif (ctype_alpha($c)) {
                $tag = self::tag($html, $i + 1, $ctx);

                if ($tag === null) {
                    break;
                }

                $name = strtolower(substr($html, $i + 1, strcspn($html, self::SPACE . '/>', $i + 1)));
                $close = $tag['end'];

                if ($name === 'plaintext') {
                    break;
                }

                if (in_array($name, self::RAW_TEXT, true)) {
                    if (self::ok(preg_match('#</' . $name . '(?=[\t\n\f\r />])#i', $html, $m, PREG_OFFSET_CAPTURE, $close + 1)) !== 1) {
                        if ($name === 'iframe') {
                            // Never closed: still real markup, but no wrapper can be placed.
                            $real[$i] = -1;
                        }

                        break;
                    }

                    // A "<!--" inside script data starts the double-escape states, whose end a browser finds differently.
                    if ($name === 'script' && str_contains(substr($html, $close + 1, $m[0][1] - $close - 1), '<!--')) {
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

    }

    /**
     * @param list<array{at: int, nameEnd: int, attrs: list<array{name: string, at: int, value: string}>, service: array{name: string, category: string, categoryTitle: string, domains: list<string>}, values: array<string, string>}> $found
     * @param array{message: string, accept: string, customise: string}                                                                                                                                                                 $text
     * @param array{budget: int, q: array<string, array{0: int, 1: int|false}>, gt: int|false, memo: array<int, array{0: int|null, 1: list<array{name: string, at: int, value: string}>}>}                                              $ctx
     *
     * @return array<int, array{0: int, 1: int, 2: int, 3: string}> keyed by offset * 4 + order; offset, order, bytes to delete, text to insert
     */
    private static function edits(string $html, array $found, array $text, array &$ctx): array
    {
        $realCtx = self::context($html, 2, 1_000_000);
        $real = [];

        try {
            self::real($html, $realCtx, $real);
        } catch (\RuntimeException) {
            // Out of work budget: iframes confirmed so far keep their placeholder, the rest are blocked without one.
        }

        $edits = [];

        foreach ($found as $f) {
            $category = $f['service']['category'];
            $next = $html[$f['nameEnd']] ?? '';
            $edits[$f['nameEnd'] * 4 + 3] = [$f['nameEnd'], 1, 0, ' data-cmk-embed=' . $category . ($next === '/' ? ' ' : '')];

            foreach ($f['attrs'] as $attribute) {
                if ($attribute['name'] === 'src') {
                    $edits[$attribute['at'] * 4 + 2] = [$attribute['at'], 0, 3, 'data-cmk-src'];
                } elseif (str_starts_with($attribute['name'], 'data-cmk-')) {
                    $edits[($attribute['at'] + 8) * 4 + 2] = [$attribute['at'] + 8, 0, 1, '_'];
                }
            }

            $closer = $real[$f['at']] ?? -1;

            if ($closer < 0) {
                continue;
            }

            $end = self::tag($html, $closer + 2, $ctx);

            if ($end === null) {
                continue;
            }

            $edits[$f['at'] * 4 + 1] = [$f['at'], 0, 0, self::placeholder($f['values'], $f['service'], $text)];
            $edits[($end['end'] + 1) * 4] = [$end['end'] + 1, 0, 0, '</span>'];
        }

        return $edits;
    }

    /**
     * @param array<int, array{0: int, 1: int, 2: int, 3: string}> $edits
     */
    private static function splice(string $html, array $edits): string
    {
        ksort($edits);

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

        $width = trim(self::decode($values['width'] ?? ''));
        $height = trim(self::decode($values['height'] ?? ''));

        if (self::ok(preg_match('/^\d{1,4}\z/', $width)) === 1) {
            $style .= 'width:' . (int) $width . 'px;';
        }

        if (self::ok(preg_match('/^\d{1,4}\z/', $height)) === 1) {
            $style .= 'min-height:' . (int) $height . 'px;';
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
     * The coarse cross-check (see tag() and real() for the grammar it is cruder than). For every iframe start tag, read
     * up to its next `>` and extended only while a quote that opens an attribute value (`=` then a quote) is unclosed,
     * the first `src` in attribute-name position (outside every quoted value) is folded as hard as it can be (tabs and
     * newlines dropped, every character reference decoded even without a semicolon, percent-decoded, NFKC-folded,
     * invisible characters dropped) and its value is tested for a known domain at a label boundary in the authority,
     * ignoring path, query and fragment. A tag with any `srcdoc` loads nothing and is skipped. It may over-report; it
     * shares no code with decode(), parse() or match().
     *
     * @param list<array{name: string, category: string, categoryTitle: string, domains: list<string>}> $services
     *
     * @return list<int>
     */
    private static function coarse(string $html, array $services): array
    {
        $patterns = [];

        foreach ($services as $service) {
            foreach ($service['domains'] as $domain) {
                $base = self::base($domain);

                if ($base !== '') {
                    $patterns[] = '~^src\s*=[\x00-\x20"\']*(?:https?:|//)/*(?:[^/?#\s"\'>@]*@)?(?:[a-z0-9-]*\.)*' . preg_quote($base, '~') . '\.?(?=[/:?#\s"\'>]|$)~';
                }
            }
        }

        self::ok(preg_match_all(self::OCCURRENCE, $html, $matches, PREG_OFFSET_CAPTURE));

        $last = strrpos($html, '>');
        $budget = 2 * strlen($html) + 1_000_000;
        $live = [];

        foreach ($matches[0] as [, $at]) {
            if ($last === false || $at > $last) {
                continue;
            }

            $end = strpos($html, '>', $at);

            for ($extend = 0; $extend < 8 && $end !== false && $end < $last && self::quoted(substr($html, $at, $end - $at))[1]; ++$extend) {
                $end = strpos($html, '>', $end + 1);
            }

            $raw = substr($html, $at, ($end === false ? $last : $end) - $at);
            $budget -= 3 * strlen($raw);

            if ($budget < 0) {
                throw new \RuntimeException('The page is too tangled to scan safely.');
            }

            [$ranges] = self::quoted($raw);
            self::ok(preg_match_all('/(?<=[\t\n\f\r \/"\'])(srcdoc|src)(?=[\t\n\f\r \/=>]|$)/i', $raw, $names, PREG_OFFSET_CAPTURE));

            $source = null;
            $srcdoc = false;

            foreach ($names[1] as [$name, $position]) {
                foreach ($ranges as [$from, $to]) {
                    if ($position >= $from && $position < $to) {
                        continue 2;
                    }
                }

                if (strtolower($name) === 'srcdoc') {
                    $srcdoc = true;
                } elseif ($source === null) {
                    $source = $position;
                }
            }

            if ($srcdoc || $source === null) {
                continue;
            }

            $folded = self::fold(substr($raw, $source));

            foreach ($patterns as $pattern) {
                if (self::ok(preg_match($pattern, $folded)) === 1) {
                    $live[] = $at;

                    break;
                }
            }
        }

        return $live;
    }

    /**
     * The quoted attribute values in some tag text (a quote only counts where it follows `=`), as [start, end) ranges
     * of their contents, and whether the last one never closes.
     *
     * @return array{0: list<array{0: int, 1: int}>, 1: bool}
     */
    private static function quoted(string $raw): array
    {
        $ranges = [];
        $offset = 0;

        while (self::ok(preg_match('/=[\t\n\f\r ]*(["\'])/', $raw, $m, PREG_OFFSET_CAPTURE, $offset)) === 1) {
            $open = $m[1][1] + 1;
            $close = strpos($raw, $m[1][0], $open);

            if ($close === false) {
                $ranges[] = [$open, strlen($raw)];

                return [$ranges, true];
            }

            $ranges[] = [$open, $close];
            $offset = $close + 1;
        }

        return [$ranges, false];
    }

    private static function fold(string $raw): string
    {
        $text = str_replace(["\t", "\r", "\n"], '', $raw);
        $text = self::str(preg_replace_callback('/&(?:#[xX]([0-9a-fA-F]+)|#([0-9]+)|([A-Za-z][A-Za-z0-9]*));?/', static function (array $m): string {
            if (($m[3] ?? '') !== '') {
                for ($length = strlen($m[3]); $length >= 2; --$length) {
                    $prefix = substr($m[3], 0, $length);
                    $decoded = html_entity_decode('&' . $prefix . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    if ($decoded !== '&' . $prefix . ';') {
                        return $decoded . substr($m[3], $length);
                    }
                }

                return $m[0];
            }

            $hex = $m[1] !== '';
            $digits = ltrim($hex ? $m[1] : ($m[2] ?? ''), '0');
            $code = $digits === '' || strlen($digits) > 8 ? 0 : ($hex ? (int) hexdec($digits) : (int) $digits);

            return $code > 0 && $code <= 0x10FFFF && ($code < 0xD800 || $code > 0xDFFF) ? (string) mb_chr($code, 'UTF-8') : "\u{FFFD}";
        }, $text));

        $text = rawurldecode($text);

        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        }

        $text = str_replace(["\xC2\xAD", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D", "\xE2\x81\xA0", "\xEF\xBB\xBF", "\xCD\x8F", "\xE3\x80\x82", "\xEF\xBD\xA1", '\\'], ['', '', '', '', '', '', '', '.', '.', '/'], $text);
        $text = strtolower(mb_strtolower($text, 'UTF-8'));

        $text = self::str(preg_replace('~/{2,}~', '//', $text));

        // The authority keeps only [a-z0-9.@:-], like guess(): characters a browser ignores must not hide a domain.
        return self::str(preg_replace_callback('~(?:https?:|//)/*+([^/?#\s"\'>]*)~', static fn(array $m): string => substr($m[0], 0, strlen($m[0]) - strlen($m[1])) . self::str(preg_replace('~[^a-z0-9.@:-]+~', '', $m[1])), $text, 1));
    }

    /**
     * Decodes character references the way a browser reads an attribute value, in one pass (no double decoding):
     * numeric references with any number of digits, with or without the semicolon, out-of-range values becoming
     * U+FFFD; named references with the semicolon; and the legacy names without one, unless the next character is
     * alphanumeric or `=`.
     */
    private static function decode(string $value): string
    {
        if (!str_contains($value, '&')) {
            return $value;
        }

        return self::str(preg_replace_callback('/&(?:#[xX]([0-9a-fA-F]+)|#([0-9]+)|([A-Za-z][A-Za-z0-9]*))(;?)(?=(.?))/s', static function (array $m): string {
            if ($m[3] === '') {
                $hex = $m[1] !== '';
                $digits = ltrim($hex ? $m[1] : $m[2], '0');
                $code = $digits === '' || strlen($digits) > ($hex ? 6 : 7) ? 0 : ($hex ? (int) hexdec($digits) : (int) $digits);

                return $code > 0 && $code <= 0x10FFFF && ($code < 0xD800 || $code > 0xDFFF) ? (string) mb_chr($code, 'UTF-8') : "\u{FFFD}";
            }

            $name = $m[3];

            if ($m[4] === ';') {
                $decoded = html_entity_decode('&' . $name . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if ($decoded !== '&' . $name . ';') {
                    return $decoded;
                }
            }

            for ($length = min(strlen($name), 6); $length >= 2; --$length) {
                $prefix = substr($name, 0, $length);

                if (!in_array($prefix, self::LEGACY, true)) {
                    continue;
                }

                // In an attribute value a legacy reference without a semicolon is literal before "=" or an alphanumeric.
                if ($length < strlen($name) || ($m[4] === '' && $m[5] === '=')) {
                    return $m[0];
                }

                return html_entity_decode('&' . $prefix . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8') . $m[4];
            }

            return $m[0];
        }, $value));
    }

    /**
     * Reads a URL the way a browser does before a request: strips surrounding controls and every tab and newline,
     * turns backslashes into slashes, treats a scheme-relative start as https, and ignores userinfo and port. Only
     * http(s) is returned. `host` is null when the host cannot be validated; `authority` is then the raw section.
     *
     * @return array{authority: string, host: string|null, guess: string|null}|null
     */
    private static function parse(string $url): ?array
    {
        $url = trim($url, "\x00..\x20");
        $url = str_replace(["\t", "\r", "\n", '\\'], ['', '', '', '/'], $url);

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        if (self::ok(preg_match('#^(https?):/*+(.*+)\z#is', $url, $m)) !== 1) {
            return null;
        }

        $scheme = strtolower($m[1]);
        $rest = $m[2];
        $end = strcspn($rest, '/?#');
        $authority = substr($rest, 0, $end);
        $afterAuthority = substr($rest, $end);
        $at = strrpos($authority, '@');
        $hostPart = $at === false ? $authority : substr($authority, $at + 1);

        $host = null;
        $guess = null;

        if (!str_starts_with($hostPart, '[')) {
            $hostPart = self::str(preg_replace('/:\d*+\z/', '', $hostPart));
            $host = self::cleanHost($hostPart);
            $guess = $host === null ? self::guess($hostPart) : null;
        }

        return [
            'authority' => $host === null ? $authority . $afterAuthority : $authority,
            'host' => $host,
            'guess' => $guess,
        ];
    }

    /**
     * Fail-toward-blocking reading of a host that could not be validated (the server's IDNA tables can lag a
     * browser's, which ignores characters ours rejects): NFKC, lower-case, ideographic dots to dots, every character
     * outside [a-z0-9.-] deleted, edge dots trimmed. Only ever used for hosts that already failed validation.
     * The result must hold under both old and new ICU versions: a guess that fails to match a service at a label
     * boundary is final and must not fall back to the substring check in named().
     */
    private static function guess(string $host): ?string
    {
        $host = rawurldecode($host);

        $normalizer = !self::$withoutIntl && class_exists(\Normalizer::class);

        if ($normalizer) {
            $host = \Normalizer::normalize($host, \Normalizer::FORM_KC) ?: $host;
        }

        $host = str_replace(["\xE3\x80\x82", "\xEF\xBC\x8E", "\xEF\xBD\xA1"], '.', mb_strtolower($host, 'UTF-8'));

        self::assertFoldable($host, $normalizer, !self::$withoutIntl && class_exists(\IntlChar::class));
        $host = trim(self::str(preg_replace('/[^a-z0-9.-]+/', '', strtolower($host))), '.');

        return $host === '' ? null : $host;
    }

    /**
     * Fails closed when a non-ASCII character is left that this server cannot fold to ASCII: a browser with newer
     * Unicode tables may map it to a letter of a service domain. Throws, which rewrite() answers with failClosed().
     */
    private static function assertFoldable(string $host, bool $normalizer, bool $intlChar): void
    {
        if (self::ok(preg_match('/^[\x00-\x7F]*+\z/', $host)) === 1) {
            return;
        }

        if (!$normalizer || !$intlChar || !mb_check_encoding($host, 'UTF-8')) {
            throw new \RuntimeException('unfoldable host');
        }

        foreach (mb_str_split($host, 1, 'UTF-8') as $char) {
            $cp = mb_ord($char, 'UTF-8');

            if ($cp > 0x7F && !\IntlChar::isdefined($cp)) {
                throw new \RuntimeException('unfoldable host');
            }
        }
    }

    private static function cleanHost(string $host): ?string
    {
        $host = rtrim(strtolower(rawurldecode($host)), '.');

        if ($host === '') {
            return null;
        }

        if (self::ok(preg_match('/^[a-z0-9.-]+\z/', $host)) !== 1) {
            if (self::$withoutIntl || !function_exists('idn_to_ascii')) {
                return null;
            }

            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46);
            $host = is_string($ascii) ? rtrim(strtolower($ascii), '.') : '';
        }

        return self::ok(preg_match('/^[a-z0-9.-]+\z/', $host)) === 1 ? $host : null;
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
     *
     * @return array{name: string, category: string, categoryTitle: string, domains: list<string>}|null
     */
    private static function matchHost(string $host, array $services): ?array
    {
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

    private static function startsWithSrcdoc(string $html, int $from): bool
    {
        $at = $from + strspn($html, self::SPACE . '/', $from);

        return strncasecmp(substr($html, $at, 6), 'srcdoc', 6) === 0 && ($at + 6 >= strlen($html) || strpbrk($html[$at + 6], self::SPACE . '/>=') !== false);
    }

    private static function tooMany(string $html): bool
    {
        $count = 0;
        $offset = 0;

        while (($at = stripos($html, '<iframe', $offset)) !== false) {
            $offset = $at + 7;

            if (++$count > 1000) {
                return true;
            }
        }

        return false;
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

        if (strlen($html) > 4_194_304 || self::tooMany($html)) {
            self::report($onError, new \RuntimeException('The page is too large to rewrite; its iframes were blocked.'));

            return self::failClosed($html);
        }

        try {
            $ctx = self::context($html, 2, 500_000);
            $found = self::occurrences($html, $services, $ctx);

            if ($found === []) {
                if (self::coarse($html, $services) !== []) {
                    throw new \RuntimeException('An iframe the matcher did not recognise still looks like a known service.');
                }

                return $html;
            }

            $out = self::splice($html, self::edits($html, $found, $text, $ctx));

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
     * Inserts a bare ` srcdoc` right after every `<iframe` followed by whitespace, `/` or `>`. No parsing, no PCRE, no
     * quotes; an iframe that already starts with `srcdoc` is skipped, so running it twice changes nothing.
     */
    public static function failClosed(string $html): string
    {
        $out = '';
        $copied = 0;
        $offset = 0;

        while (($at = stripos($html, '<iframe', $offset)) !== false) {
            $end = $at + 7;
            $offset = $end;

            if (!self::delimited($html, $end) || self::startsWithSrcdoc($html, $end)) {
                continue;
            }

            $out .= substr($html, $copied, $end - $copied) . ' srcdoc';
            $copied = $end;
        }

        return $out . substr($html, $copied);
    }

    /**
     * Offsets of iframe start tags whose `src` still names a known service: the matcher's reading plus a deliberately
     * coarse second reading that does not share the matcher's decoding, URL parsing or domain matching.
     *
     * @param list<array{name: string, category: string, categoryTitle: string, domains: list<string>}> $services
     *
     * @return list<int>
     */
    public static function audit(string $html, array $services): array
    {
        $ctx = self::context($html, 4, 1_000_000);
        $strict = array_column(self::occurrences($html, $services, $ctx), 'at');

        return array_values(array_unique(array_merge($strict, self::coarse($html, $services))));
    }

    /**
     * The lower-case ASCII host of an http(s) URL, read the way a browser reads it, or null.
     */
    public static function host(string $url): ?string
    {
        try {
            return self::parse($url)['host'] ?? null;
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * @param list<array{name: string, category: string, categoryTitle: string, domains: list<string>}> $services
     *
     * @return array{name: string, category: string, categoryTitle: string, domains: list<string>}|null
     */
    public static function match(string $url, array $services): ?array
    {
        $host = self::host($url);

        return $host === null ? null : self::matchHost($host, $services);
    }
}
