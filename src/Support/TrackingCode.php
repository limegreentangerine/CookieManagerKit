<?php

namespace CookieManagerKit\Support;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Makes a pasted tracking snippet (Concrete's SEO tracking code) inert until its category is consented.
 * The snippet is parsed with the WHATWG HTML parser, so the scripts found are exactly those a browser would run;
 * hand-written tokenizing cannot be kept in step with comments, raw-text elements and attribute quoting.
 * <noscript> elements are removed: a visitor without JavaScript cannot consent, so their pixel must not load.
 */
final class TrackingCode
{
    private static function rewrite(string $code, string $category): string
    {
        $holder = HTMLDocument::createEmpty()->createElement('div');
        $holder->innerHTML = $code;

        self::process($holder, $category);

        return $holder->innerHTML;
    }

    private static function process(Element $root, string $category): void
    {
        foreach (iterator_to_array($root->querySelectorAll('noscript')) as $noscript) {
            $noscript->remove();
        }

        foreach ($root->querySelectorAll('script') as $script) {
            $type = (string) $script->getAttribute('type'); // the parser already kept the first duplicate

            foreach (iterator_to_array($script->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);

                if ($name === 'type' || str_starts_with($name, 'data-cmk-')) {
                    $script->removeAttribute($attribute->nodeName);
                }
            }

            $script->setAttribute('type', 'text/plain');
            $script->setAttribute('data-cmk-category', $category);

            if (
                preg_match('#^[a-z0-9/+.\-]{1,64}\z#i', $type) === 1
                && !in_array(strtolower($type), ['text/javascript', 'application/javascript', 'text/plain'], true)
            ) {
                $script->setAttribute('data-cmk-type', $type);
            }
        }

        // A template's content is a separate fragment that querySelectorAll does not enter (and PHP exposes
        // no accessor for it), so its markup is rewritten as a snippet of its own and put back.
        foreach (iterator_to_array($root->querySelectorAll('template')) as $template) {
            $template->innerHTML = self::rewrite($template->innerHTML, $category);
        }
    }
    public static function makeInert(string $code, string $category): string
    {
        if (!Text::isHandle($category)) {
            return '';
        }

        try {
            return self::rewrite($code, $category);
        } catch (\Throwable) {
            return ''; // fail closed
        }
    }
}
