<?php

namespace CookieManagerKit\Support;

/**
 * For template and block authors: one inert script tag that the runtime activates once its category is consented.
 */
final class Inert
{
    public static function script(string $category, string $src = '', string $inline = '', string $type = ''): string
    {
        if (!Text::isHandle($category)) {
            return '';
        }

        $src = $src === '' ? '' : Output::safeUrl($src);

        if ($src === '' && $inline === '') {
            return '';
        }

        $attributes = ' type="text/plain" data-cmk-category="' . $category . '"';

        if ($src !== '') {
            $attributes .= ' src="' . Output::e($src) . '"';
        }

        if (preg_match('#^[a-z0-9/+.\-]{1,64}\z#i', $type) === 1 && !in_array(strtolower($type), ['text/javascript', 'application/javascript'], true)) {
            $attributes .= ' data-cmk-type="' . $type . '"';
        }

        // "</script", "<script" and "<!--" inside inline code can end the element early or put the parser in the
        // double-escaped state, where our closing tag no longer closes it.
        $body = (string) preg_replace(['#</(script)#i', '#<(script)#i', '#<!--#'], ['<\\/$1', '<\\$1', '<\\!--'], $inline);

        return '<script' . $attributes . '>' . $body . '</script>';
    }
}
