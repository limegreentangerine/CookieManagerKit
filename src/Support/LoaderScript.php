<?php

namespace CookieManagerKit\Support;

/**
 * The inline <script> that goes into <head>: the boot data followed by the loader. Inlined (not an external file)
 * so consent is read before any other script can run. Output is identical for every visitor.
 */
final class LoaderScript
{
    private static ?string $source = null;

    public static function isValidNonce(?string $nonce): bool
    {
        return $nonce !== null && preg_match('#^[A-Za-z0-9+/=_-]{8,128}\z#', $nonce) === 1;
    }

    public static function source(): string
    {
        if (self::$source === null) {
            $path = dirname(__DIR__, 2) . '/js/cmk-loader.js';
            $code = is_file($path) ? (string) file_get_contents($path) : '';

            // The loader never contains a closing tag, and this makes sure an edit can never introduce one.
            self::$source = (string) preg_replace('#</(script)#i', '<\\/$1', $code);
        }

        return self::$source;
    }

    /**
     * The boot data the loader reads. `nonce` tells the loader the page carries a CSP nonce, so it only activates
     * inert scripts bearing its own; it is true only when inline() will actually emit the nonce attribute.
     *
     * @param  list<string>                                                                         $categories
     * @return array{v: int, cats: list<string>, days: int, cfg: string, post: string, nonce: bool}
     */
    public static function boot(int $version, array $categories, int $lifetimeDays, string $configUrl, string $postUrl, ?string $nonce): array
    {
        return [
            'v' => $version,
            'cats' => $categories,
            'days' => $lifetimeDays,
            'cfg' => $configUrl,
            'post' => $postUrl,
            'nonce' => self::isValidNonce($nonce),
        ];
    }

    /**
     * @param array<string, mixed> $boot
     */
    public static function inline(array $boot, ?string $nonce = null): string
    {
        $attribute = self::isValidNonce($nonce) ? ' nonce="' . Output::e($nonce) . '"' : '';

        return '<script' . $attribute . '>window.cmkBoot=' . Output::json($boot) . ';' . self::source() . '</script>';
    }
}
