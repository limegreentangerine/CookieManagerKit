<?php

namespace CookieManagerKit\Support;

/**
 * Pure: blocks iframes that belong to a known service until the visitor consents to its category. A matched iframe
 * becomes a placeholder (message, accept button, preferences button) next to an iframe whose `src` has moved to
 * `data-cmk-src`; the loader restores it in the browser. The output does not depend on the visitor.
 *
 * Fails closed: a matched iframe that cannot be rewritten safely is removed, and a regex failure removes every
 * iframe tag from the page rather than leaving one live.
 */
final class EmbedRewriter
{
    /** Regions where an `<iframe` is text, not an element. Unterminated ones run to the end, as a browser reads them. */
    private const MASKED = '#<!--.*?(?:-->|\z)|<(script|style|template|noscript)(?=[\s/>])(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>.*?(?:</\1\s*>|\z)|(<[a-z][^\s/>]*+(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>)#is';
    /** The second alternative catches a tag whose quotes never close; one() removes it if it names a known service. */
    private const IFRAME = '#<iframe(?=[\s/>])(?:((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>(?:.*?</iframe\s*>)?|[^>]*+>?)#is';
    private const ATTRIBUTE = '#([^\s"\'<>/=]++)(?:\s*+=\s*+(?:"([^"]*+)"|\'([^\']*+)\'|([^\s"\'=<>`]++)))?#';

    /**
     * @param list<array{name: string, category: string, categoryTitle: string, domains: list<string>}> $services
     * @param array{message: string, accept: string, customise: string}                                 $text
     */
    private static function one(string $whole, ?string $attributeText, array $services, array $text, callable $onError): string
    {
        try {
            if ($attributeText === null) {
                return self::names($whole, $services) ? self::drop($onError) : $whole;
            }

            $attributes = self::attributes($attributeText);
            $source = $attributes['src'] ?? null;

            if ($source === null || isset($attributes['srcdoc'])) {
                return $whole;
            }

            $source = trim($source);
            $source = str_starts_with($source, '//') ? 'https:' . $source : $source;
            $url = Output::safeUrl($source);
            $service = $url === '' ? null : self::match($url, $services);

            if ($service === null || !Text::isHandle($service['category'])) {
                return $whole;
            }

            if (preg_match('#</iframe\s*+>\z#i', $whole) !== 1) {
                return self::drop($onError, 'An unterminated iframe was removed.');
            }

            return self::placeholder($attributes, $url, $service, $text);
        } catch (\Throwable $e) {
            self::report($onError, $e);

            return '';
        }
    }

    /**
     * @param array<string, string>                                                               $attributes
     * @param array{name: string, category: string, categoryTitle: string, domains: list<string>} $service
     * @param array{message: string, accept: string, customise: string}                           $text
     */
    private static function placeholder(array $attributes, string $url, array $service, array $text): string
    {
        $kept = '';

        foreach ($attributes as $name => $value) {
            if ($name === 'src' || str_starts_with($name, 'data-cmk-') || preg_match('/^[a-z][a-z0-9_:-]{0,63}\z/', $name) !== 1) {
                continue;
            }

            $kept .= ' ' . $name . '="' . Output::e($value) . '"';
        }

        $style = '';

        if (preg_match('/^(\d{1,4})(px)?\z/', $attributes['width'] ?? '', $width) === 1) {
            $style .= 'width:' . $width[1] . 'px;';
        }

        if (preg_match('/^(\d{1,4})(px)?\z/', $attributes['height'] ?? '', $height) === 1) {
            $style .= 'min-height:' . $height[1] . 'px;';
        }

        $replace = ['{service}' => $service['name'], '{category}' => $service['categoryTitle']];
        $category = Output::e($service['category']);

        return '<span class="cmk-embed" data-cmk-embed="' . $category . '"' . ($style === '' ? '' : ' style="' . Output::e($style) . '"') . '>'
            . '<span class="cmk-embed__box" data-cmk-embed-placeholder>'
            . '<span class="cmk-embed__message">' . Output::e(strtr($text['message'], $replace)) . '</span>'
            . '<span class="cmk-embed__actions">'
            . '<button type="button" class="cmk-embed__accept" data-cmk-embed-accept="' . $category . '">' . Output::e(strtr($text['accept'], $replace)) . '</button> '
            . '<button type="button" class="cmk-embed__prefs" data-cmk-open>' . Output::e($text['customise']) . '</button>'
            . '</span></span>'
            . '<iframe' . $kept . ' data-cmk-src="' . Output::e($url) . '"></iframe>'
            . '</span>';
    }

    /**
     * Attribute names are lower-cased and the first of a duplicate wins, as a browser reads them.
     *
     * @return array<string, string>
     */
    private static function attributes(string $text): array
    {
        $out = [];

        preg_match_all(self::ATTRIBUTE, $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $name = strtolower($match[1]);

            if (isset($out[$name])) {
                continue;
            }

            $value = ($match[2] ?? '') !== '' ? $match[2] : (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? ''));
            $out[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $out;
    }

    /**
     * Whether the text of a tag that could not be parsed mentions any known domain, however it is entity-encoded.
     *
     * @param list<array{name: string, category: string, categoryTitle: string, domains: list<string>}> $services
     */
    private static function names(string $text, array $services): bool
    {
        $text = strtolower(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        foreach ($services as $service) {
            foreach ($service['domains'] as $domain) {
                $base = strtolower(str_starts_with($domain, '*.') ? substr($domain, 2) : $domain);

                if ($base !== '' && str_contains($text, $base)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function drop(callable $onError, string $message = 'An iframe that could not be parsed was removed.'): string
    {
        self::report($onError, new \RuntimeException($message));

        return '';
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

        $token = 'cmk' . bin2hex(random_bytes(8));
        $masks = [];
        $masked = preg_replace_callback(self::MASKED, static function (array $found) use (&$masks, $token): string {
            // Ordinary tags are only consumed so that a "<script>" inside an attribute value is not read as an element.
            if (isset($found[2])) {
                return $found[0];
            }

            $key = '<!--' . $token . count($masks) . '-->';
            $masks[$key] = $found[0];

            return $key;
        }, $html);

        $done = $masked === null ? null : preg_replace_callback(
            self::IFRAME,
            static fn(array $found): string => self::one($found[0], $found[1] ?? null, $services, $text, $onError),
            $masked,
        );

        if ($done === null) {
            self::report($onError, new \RuntimeException('Embed rewriting failed; iframes were removed from the page.'));

            return (string) preg_replace('#</?iframe(?=[\s/>])[^>]*+>#i', '', $html);
        }

        return $masks === [] ? $done : strtr($done, $masks);
    }

    /**
     * The lower-case host of an http(s) URL, or null.
     */
    public static function host(string $url): ?string
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host)) {
            return null;
        }

        $host = rtrim(strtolower($host), '.');

        return preg_match('/^[a-z0-9.-]+\z/', $host) === 1 ? $host : null;
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
                $base = str_starts_with($domain, '*.') ? substr($domain, 2) : $domain;

                if ($base !== '' && ($host === $base || str_ends_with($host, '.' . $base))) {
                    return $service;
                }
            }
        }

        return null;
    }
}
