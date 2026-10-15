<?php

namespace CookieManagerKit\Support;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\ParentNode;

/**
 * Makes a pasted tracking snippet (Concrete's SEO tracking code) inert until its category is consented.
 * The snippet is parsed with the WHATWG HTML parser, so the scripts found are exactly those a browser would run;
 * hand-written tokenizing cannot be kept in step with comments, raw-text elements and attribute quoting.
 * <noscript> elements are removed: a visitor without JavaScript cannot consent, so their pixel must not load.
 * Only scripts a browser would execute are held back; data blocks (JSON-LD, importmap, speculation rules, templates
 * with a non-JavaScript type) never run and crawlers never consent, so they are left exactly as parsed.
 * The result is verified by parsing it again (see verify()); anything that does not survive the round trip as the
 * same inert scripts is refused, because the serialised form of malformed markup can parse differently.
 */
final class TrackingCode
{
    private const JAVASCRIPT_TYPES = [
        'application/ecmascript', 'application/javascript', 'application/x-ecmascript', 'application/x-javascript',
        'text/ecmascript', 'text/javascript', 'text/javascript1.0', 'text/javascript1.1', 'text/javascript1.2',
        'text/javascript1.3', 'text/javascript1.4', 'text/javascript1.5', 'text/jscript', 'text/livescript',
        'text/x-ecmascript', 'text/x-javascript',
    ];

    /**
     * The type's MIME essence: ASCII-whitespace trimmed, parameters dropped, lower-cased.
     */
    private static function essence(?string $type): string
    {
        return strtolower(trim(explode(';', (string) $type, 2)[0], " \t\n\r\f"));
    }

    /**
     * Would a browser execute a script of this type? (WHATWG "prepare the script element": empty, `module`, or a
     * JavaScript MIME type essence.)
     */
    private static function isExecutable(?string $type): bool
    {
        $essence = self::essence($type);

        return $essence === '' || $essence === 'module' || in_array($essence, self::JAVASCRIPT_TYPES, true);
    }

    private static function parse(string $html): Element
    {
        $holder = HTMLDocument::createEmpty()->createElement('div');
        $holder->innerHTML = $html;

        return $holder;
    }

    /**
     * @param bool                                 $ownOutput true only when checking that our own output is a fixed point: a
     *                                                        text/plain script of our category is then ours, not pasted
     * @param array{scripts: int, inert: int}|null $stats     filled for the top-level fragment only
     * @param-out array{scripts: int, inert: int} $stats
     */
    private static function rewrite(string $code, string $category, ?string $nonce, bool $ownOutput = false, ?array &$stats = null): string
    {
        $holder = self::parse($code);

        $stats = self::process($holder, $category, $nonce, $ownOutput);

        return $holder->innerHTML;
    }

    /**
     * @return array{scripts: int, inert: int} every script in the fragment, and how many of them were made inert
     */
    private static function process(Element $root, string $category, ?string $nonce, bool $ownOutput): array
    {
        // Tracking snippets never need <svg> or <math>, and foreign content is where the HTML parser's fragment and
        // document modes disagree (a stray <p> leaves it only in a document), so it is refused before anything else.
        if ($root->querySelector('svg, math') !== null) {
            throw new \UnexpectedValueException('Foreign content (svg/math) is not allowed in a tracking snippet.');
        }

        $scripts = 0;
        $inert = 0;

        foreach (iterator_to_array($root->querySelectorAll('noscript')) as $noscript) {
            $noscript->remove();
        }

        foreach ($root->querySelectorAll('script') as $script) {
            ++$scripts;
            $type = (string) $script->getAttribute('type'); // the parser already kept the first duplicate

            // Not executed by any browser, so nothing to hold back and the rest stays as parsed. But the loader
            // activates any script[type="text/plain"][data-cmk-category], so pasted data-cmk-* attributes (a forged
            // category) are always removed, and on a text/plain script a pasted nonce goes too (it would pass the
            // loader's nonce check).
            if (!self::isExecutable($type)) {
                $plain = self::essence($type) === 'text/plain';

                if ($ownOutput && $plain && $script->getAttribute('data-cmk-category') === $category) {
                    continue;
                }

                foreach (iterator_to_array($script->attributes) as $attribute) {
                    $name = strtolower($attribute->nodeName);

                    if (str_starts_with($name, 'data-cmk-') || ($plain && $name === 'nonce')) {
                        $script->removeAttribute($attribute->nodeName);
                    }
                }

                continue;
            }

            ++$inert;

            foreach (iterator_to_array($script->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);

                if ($name === 'type' || $name === 'nonce' || str_starts_with($name, 'data-cmk-')) {
                    $script->removeAttribute($attribute->nodeName);
                }
            }

            $script->setAttribute('type', 'text/plain');
            $script->setAttribute('data-cmk-category', $category);

            if (LoaderScript::isValidNonce($nonce)) {
                $script->setAttribute('nonce', (string) $nonce);
            }

            $remembered = self::essence($type);

            if (preg_match('#^[a-z0-9/+.\-]{1,64}\z#', $remembered) === 1 && !in_array($remembered, ['text/javascript', 'application/javascript'], true)) {
                $script->setAttribute('data-cmk-type', $remembered);
            }
        }

        // A template's content is a separate fragment that querySelectorAll does not enter (and PHP exposes
        // no accessor for it), so its markup is rewritten as a snippet of its own and put back.
        foreach (iterator_to_array($root->querySelectorAll('template')) as $template) {
            $template->innerHTML = self::rewrite($template->innerHTML, $category, $nonce, $ownOutput);
        }

        return ['scripts' => $scripts, 'inert' => $inert];
    }

    /**
     * @return list<Element> every script element under the node, in document order (template contents excluded)
     */
    private static function scripts(ParentNode $root): array
    {
        return iterator_to_array($root->querySelectorAll('script'), false);
    }

    /**
     * Checks the scripts of one parse of the output: none may be executable, none may appear or disappear, and every
     * script made inert must still be inert (with the nonce when given).
     *
     * @param array{scripts: int, inert: int} $stats from the first pass
     */
    private static function scriptsHold(ParentNode $parsed, string $category, ?string $nonce, array $stats): bool
    {
        $scripts = self::scripts($parsed);
        $inert = 0;

        foreach ($scripts as $script) {
            if (self::isExecutable($script->getAttribute('type'))) {
                return false;
            }

            if (
                $script->getAttribute('type') === 'text/plain'
                && $script->getAttribute('data-cmk-category') === $category
                && (!LoaderScript::isValidNonce($nonce) || $script->getAttribute('nonce') === $nonce)
            ) {
                ++$inert;
            }
        }

        return count($scripts) === $stats['scripts'] && $inert >= $stats['inert'];
    }

    /**
     * The serialised output is parsed again, the way a browser will parse the page: as a fragment, and as a full
     * document in both places a snippet can land (body and head), because a document parse breaks out of foreign
     * content where the fragment parser does not. In every parse no script may be executable, no script may appear
     * or disappear, and every script made inert must still be inert (with the nonce when given). Rewriting the
     * output again must also change nothing.
     *
     * @param array{scripts: int, inert: int} $stats from the first pass
     */
    private static function verify(string $output, string $category, ?string $nonce, array $stats): bool
    {
        $parses = [
            self::parse($output),
            HTMLDocument::createFromString('<!DOCTYPE html><html><head></head><body>' . $output . '</body></html>', LIBXML_NOERROR),
            HTMLDocument::createFromString('<!DOCTYPE html><html><head>' . $output . '</head><body></body></html>', LIBXML_NOERROR),
        ];

        foreach ($parses as $parsed) {
            if (!self::scriptsHold($parsed, $category, $nonce, $stats)) {
                return false;
            }
        }

        return self::rewrite($output, $category, $nonce, true) === $output;
    }

    /**
     * A pasted nonce on an executable script never survives. A valid $nonce (the page's CSP nonce) is stamped on every
     * script made inert so the loader, which in nonce mode only activates scripts bearing its own nonce, can run
     * them after consent. Returns '' (fail closed) for an invalid category or anything that cannot be verified.
     */
    public static function makeInert(string $code, string $category, ?string $nonce = null): string
    {
        if (!Text::isHandle($category)) {
            return '';
        }

        try {
            $output = self::rewrite($code, $category, $nonce, false, $stats);

            return self::verify($output, $category, $nonce, $stats) ? $output : '';
        } catch (\Throwable) {
            return ''; // fail closed
        }
    }
}
