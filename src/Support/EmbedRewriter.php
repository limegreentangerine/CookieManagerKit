<?php

namespace CookieManagerKit\Support;

/**
 * Blocks iframes that belong to a known service until the visitor consents to its category. A matched iframe
 * becomes a placeholder (message, accept button, preferences button) next to a fresh iframe whose `src` has moved to
 * `data-cmk-src`; the loader restores it in the browser. The output does not depend on the visitor.
 *
 * The page is read with PHP's spec-compliant HTML5 parser, so what counts as an iframe is what a browser would see.
 * Fails closed: an iframe that cannot be rewritten is removed, and a parse failure removes every iframe tag.
 */
final class EmbedRewriter
{
    /**
     * @param list<array{name: string, category: string, categoryTitle: string, domains: list<string>}> $services
     * @param array{message: string, accept: string, customise: string}                                 $text
     */
    private static function one(\Dom\HTMLDocument $doc, \Dom\Element $iframe, array $services, array $text, callable $onError): bool
    {
        try {
            if ($iframe->hasAttribute('srcdoc') || !$iframe->hasAttribute('src')) {
                return false;
            }

            $parsed = self::parse((string) $iframe->getAttribute('src'));

            if ($parsed === null) {
                return false;
            }

            $service = $parsed['host'] !== null ? self::match($parsed['url'], $services) : self::named($parsed['authority'], $services);

            if ($service === null || !Text::isHandle($service['category'])) {
                return false;
            }
        } catch (\Throwable $e) {
            self::report($onError, $e);

            return self::remove($iframe, $onError);
        }

        try {
            $iframe->replaceWith(self::placeholder($doc, $iframe, $parsed['url'], $service, $text));
        } catch (\Throwable $e) {
            self::report($onError, $e);
            self::remove($iframe, $onError);
        }

        return true;
    }

    private static function remove(\Dom\Element $iframe, callable $onError): bool
    {
        try {
            $iframe->remove();
        } catch (\Throwable $e) {
            self::report($onError, $e);
        }

        return true;
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
        $authority = $rest;
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

    /**
     * @param array{name: string, category: string, categoryTitle: string, domains: list<string>} $service
     * @param array{message: string, accept: string, customise: string}                           $text
     */
    private static function placeholder(\Dom\HTMLDocument $doc, \Dom\Element $original, string $url, array $service, array $text): \Dom\Element
    {
        $replace = ['{service}' => $service['name'], '{category}' => $service['categoryTitle']];

        $element = static function (string $tag, array $attributes, ?string $content = null) use ($doc): \Dom\Element {
            $node = $doc->createElement($tag);

            foreach ($attributes as $name => $value) {
                $node->setAttribute($name, $value);
            }

            if ($content !== null) {
                $node->textContent = $content;
            }

            return $node;
        };

        $frame = $doc->createElement('iframe');

        foreach ($original->attributes as $attribute) {
            $name = $attribute->name;

            if (in_array($name, ['src', 'srcdoc'], true) || str_starts_with($name, 'data-cmk-') || preg_match('/^[a-z][a-z0-9_:-]{0,63}\z/', $name) !== 1) {
                continue;
            }

            $frame->setAttribute($name, $attribute->value);
        }

        $frame->setAttribute('data-cmk-src', $url);

        $style = '';

        if (preg_match('/^(\d{1,4})(px)?\z/', (string) $original->getAttribute('width'), $width) === 1) {
            $style .= 'width:' . $width[1] . 'px;';
        }

        if (preg_match('/^(\d{1,4})(px)?\z/', (string) $original->getAttribute('height'), $height) === 1) {
            $style .= 'min-height:' . $height[1] . 'px;';
        }

        $wrapper = $element('span', ['class' => 'cmk-embed', 'data-cmk-embed' => $service['category']] + ($style === '' ? [] : ['style' => $style]));
        $box = $element('span', ['class' => 'cmk-embed__box', 'data-cmk-embed-placeholder' => '']);
        $actions = $element('span', ['class' => 'cmk-embed__actions']);

        $box->appendChild($element('span', ['class' => 'cmk-embed__message'], strtr($text['message'], $replace)));
        $actions->appendChild($element('button', ['type' => 'button', 'class' => 'cmk-embed__accept', 'data-cmk-embed-accept' => $service['category']], strtr($text['accept'], $replace)));
        $actions->appendChild($doc->createTextNode(' '));
        $actions->appendChild($element('button', ['type' => 'button', 'class' => 'cmk-embed__prefs', 'data-cmk-open' => ''], $text['customise']));
        $box->appendChild($actions);
        $wrapper->appendChild($box);
        $wrapper->appendChild($frame);

        return $wrapper;
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
            $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
            $changed = false;

            foreach (iterator_to_array($doc->querySelectorAll('iframe'), false) as $iframe) {
                $changed = self::one($doc, $iframe, $services, $text, $onError) || $changed;
            }

            return $changed ? $doc->saveHtml() : $html;
        } catch (\Throwable $e) {
            self::report($onError, $e);

            return self::failClosed($html);
        }
    }

    /**
     * Removes every iframe tag. Used when the page cannot be parsed.
     */
    public static function failClosed(string $html): string
    {
        return preg_replace('#</?iframe(?=[\s/>])[^>]*+>?#i', '', $html) ?? '';
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
