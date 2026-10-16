<?php

namespace CookieManagerKit\Scan;

/**
 * Reads and vets the body the scan-mode beacon posts. Pure: no Concrete, no database. Same rule as StatsEvent:
 * structural violations (type, keys, size, counts) reject the whole body; individual names and hosts that are not
 * usable are skipped, because one odd entry should not lose a whole page's worth of findings.
 */
final readonly class BeaconReport
{
    public const MAX_BYTES = 32768;
    public const MAX_ITEMS = 100;

    /**
     * @param list<string> $cookies
     * @param list<string> $storage
     * @param list<string> $hosts
     */
    public function __construct(
        public string $path,
        public array $cookies,
        public array $storage,
        public array $hosts,
    ) {}

    /**
     * @return list<string>|null null when the value is not a list of at most MAX_ITEMS strings
     */
    private static function strings(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_ITEMS) {
            return null;
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                return null;
            }
        }

        return $value;
    }

    /**
     * @param list<string|null> $items
     *
     * @return list<string>
     */
    private static function unique(array $items): array
    {
        return array_values(array_unique(array_filter($items, static fn(?string $item): bool => $item !== null)));
    }

    public static function parse(?string $contentType, string $body): ?self
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

        if ($keys !== ['c', 'h', 'p', 's']) {
            return null;
        }

        $path = $data['p'];

        if (!is_string($path) || ($path !== '/' && preg_match('#^/[^/\x00-\x1F\x7F][^\x00-\x1F\x7F]{0,510}\z#', $path) !== 1)) {
            return null;
        }

        $cookies = self::strings($data['c']);
        $storage = self::strings($data['s']);
        $hosts = self::strings($data['h']);

        if ($cookies === null || $storage === null || $hosts === null) {
            return null;
        }

        return new self(
            $path,
            self::unique(array_map(static fn(string $name): ?string => Clean::name($name), $cookies)),
            self::unique(array_map(static fn(string $name): ?string => Clean::name($name), $storage)),
            self::unique(array_map(static fn(string $host): ?string => Host::normalise($host), $hosts)),
        );
    }
}
