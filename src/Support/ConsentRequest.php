<?php

namespace CookieManagerKit\Support;

/**
 * Reads and vets the body a visitor's browser posts when they make a choice. Pure: no Concrete, no database.
 * Everything arrives from the public internet, so nothing is trusted and nothing is "cleaned and kept": a body
 * that deviates in any way is rejected whole.
 */
final class ConsentRequest
{
    private const MAX_POLICY_VERSION = 1000000;
    public const MAX_BYTES = 2048;
    public const MAX_CATEGORIES = 20;

    /**
     * @param list<string> $knownHandles
     *
     * @return array{v: int, c: list<string>}|null
     */
    public static function parse(?string $contentType, string $body, array $knownHandles): ?array
    {
        if ($contentType === null || preg_match('#^application/json(?:\s*;.*)?\z#i', trim($contentType)) !== 1) {
            return null;
        }

        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        try {
            $data = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data) || array_is_list($data)) {
            return null;
        }

        $keys = array_keys($data);
        sort($keys);

        if ($keys !== ['c', 'v']) {
            return null;
        }

        $version = $data['v'];

        if (!is_int($version) || $version < 1 || $version > self::MAX_POLICY_VERSION) {
            return null;
        }

        $categories = $data['c'];

        if (!is_array($categories) || !array_is_list($categories) || count($categories) > self::MAX_CATEGORIES) {
            return null;
        }

        $accepted = ['necessary'];

        foreach ($categories as $handle) {
            if (!Text::isHandle($handle) || !in_array($handle, $knownHandles, true)) {
                return null;
            }

            if (!in_array($handle, $accepted, true)) {
                $accepted[] = $handle;
            }
        }

        return ['v' => $version, 'c' => $accepted];
    }
}
