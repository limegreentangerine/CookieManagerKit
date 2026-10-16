<?php

declare(strict_types=1);

if (!function_exists('t')) {
    /**
     * @param mixed ...$args
     */
    function t(string $text, ...$args): string
    {
        return $args === [] ? $text : vsprintf($text, $args);
    }
}
