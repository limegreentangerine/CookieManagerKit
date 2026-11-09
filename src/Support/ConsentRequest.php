<?php

namespace CookieManagerKit\Support;

use CookieManagerKit\Consent\ConsentTrigger;

/**
 * Reads and vets the body a visitor's browser posts when they make a choice. Pure: no Concrete, no database.
 * Everything arrives from the public internet, so nothing is trusted and nothing is "cleaned and kept": a body
 * that deviates in any way is rejected whole.
 */
final class ConsentRequest
{
    private const MAX_POLICY_VERSION = 1000000;
    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';
    public const MAX_BYTES = 2048;
    public const MAX_CATEGORIES = 20;

    /**
     * @param list<string> $knownHandles
     * @param list<string> $knownLocales the site's locales; empty means "any well-formed locale"
     *
     * @return array{v: int, c: list<string>, i: ?string, trigger: string, locale: string}|null
     */
    public static function parse(?string $contentType, string $body, array $knownHandles, array $knownLocales = []): ?array
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

        if (!array_key_exists('c', $data) || !array_key_exists('v', $data)) {
            return null;
        }

        if (array_diff(array_keys($data), ['c', 'v', 'i', 'g', 'l']) !== []) {
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

        $id = null;

        if (array_key_exists('i', $data)) {
            $raw = $data['i'];

            if (!is_string($raw) || strlen($raw) !== 36 || preg_match(self::GUID, strtolower($raw)) !== 1) {
                return null;
            }

            $id = strtolower($raw);
        }

        $locale = '';

        if (array_key_exists('l', $data)) {
            $raw = $data['l'];

            if (!Text::isLocale($raw) || ($knownLocales !== [] && !in_array($raw, $knownLocales, true))) {
                return null;
            }

            $locale = $raw;
        }

        if (array_key_exists('g', $data)) {
            if (!ConsentTrigger::isValid($data['g'])) {
                return null;
            }

            $trigger = $data['g'];
        } else {
            $trigger = count($accepted) === 1
                ? ConsentTrigger::REJECT_ALL
                : (array_diff($knownHandles, $accepted) === [] ? ConsentTrigger::ACCEPT_ALL : ConsentTrigger::CUSTOM);
        }

        return ['v' => $version, 'c' => $accepted, 'i' => $id, 'trigger' => $trigger, 'locale' => $locale];
    }
}
