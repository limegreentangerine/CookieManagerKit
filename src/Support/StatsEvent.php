<?php

namespace CookieManagerKit\Support;

/**
 * Reads and vets the body the runtime posts when it reports a banner event. Pure: no Concrete, no database.
 * Same rule as ConsentRequest: the body comes from the public internet, so a body that deviates in any way is
 * rejected whole and nothing is "cleaned and kept".
 */
final class StatsEvent
{
    private const MAX_POLICY_VERSION = 1000000;
    public const EVENTS = ['shown', 'accept_all', 'reject_all', 'custom', 'changed'];
    public const MAX_BYTES = 512;

    /**
     * @return array{v: int, e: string, l: string}|null
     */
    public static function parse(?string $contentType, string $body): ?array
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

        if ($keys !== ['e', 'l', 'v']) {
            return null;
        }

        $version = $data['v'];
        $event = $data['e'];
        $locale = $data['l'];

        if (!is_int($version) || $version < 1 || $version > self::MAX_POLICY_VERSION) {
            return null;
        }

        if (!is_string($event) || !in_array($event, self::EVENTS, true)) {
            return null;
        }

        if (!Text::isLocale($locale)) {
            return null;
        }

        return ['v' => $version, 'e' => $event, 'l' => $locale];
    }
}
