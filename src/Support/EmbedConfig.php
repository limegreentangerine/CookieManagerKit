<?php

namespace CookieManagerKit\Support;

/**
 * Pure: the services whose iframes are blocked and the placeholder text, from the cached snapshot. Services of the
 * always-on category, services without domains and categories with an invalid handle are never offered.
 */
final class EmbedConfig
{
    private const FALLBACK = [
        'embedMessage' => 'This content is provided by {service} and needs your consent to load.',
        'embedAcceptLabel' => 'Accept {category} cookies',
        'customise' => 'Customise',
    ];

    /**
     * @param array<string, mixed> $byLocale locale => row
     *
     * @return array<string, string> locale => the row's $key
     */
    private static function column(array $byLocale, string $key): array
    {
        $out = [];

        foreach ($byLocale as $locale => $row) {
            $out[(string) $locale] = is_array($row) && is_string($row[$key] ?? null) ? $row[$key] : '';
        }

        return $out;
    }

    /**
     * @param array<string, string> $byLocale
     */
    private static function pick(array $byLocale, string $locale, string $default): string
    {
        foreach ([$locale, $default] as $candidate) {
            if (($byLocale[$candidate] ?? '') !== '') {
                return $byLocale[$candidate];
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array{services: list<array{name: string, category: string, categoryTitle: string, domains: list<string>}>, text: array{message: string, accept: string, customise: string}}
     */
    public static function fromSnapshot(array $snapshot, string $locale, string $default): array
    {
        $services = [];

        foreach (is_array($snapshot['categories'] ?? null) ? $snapshot['categories'] : [] as $category) {
            $handle = is_array($category) ? ($category['handle'] ?? null) : null;

            if (!is_string($handle) || !Text::isHandle($handle) || ($category['necessary'] ?? false) === true) {
                continue;
            }

            $title = self::pick(self::column(is_array($category['content'] ?? null) ? $category['content'] : [], 'title'), $locale, $default) ?: $handle;

            foreach (is_array($category['services'] ?? null) ? $category['services'] : [] as $service) {
                $domains = is_array($service) && is_array($service['domains'] ?? null) ? array_values(array_filter($service['domains'], 'is_string')) : [];

                if ($domains === []) {
                    continue;
                }

                $services[] = ['name' => (string) ($service['name'] ?? ''), 'category' => $handle, 'categoryTitle' => $title, 'domains' => $domains];
            }
        }

        $banner = is_array($snapshot['banner'] ?? null) ? $snapshot['banner'] : [];
        $defaults = is_array($snapshot['defaults'] ?? null) ? $snapshot['defaults'] : [];
        $text = static fn(string $key): string => self::pick(self::column($banner, $key), $locale, $default)
            ?: (is_string($defaults[$key] ?? null) && $defaults[$key] !== '' ? $defaults[$key] : self::FALLBACK[$key]);

        return ['services' => $services, 'text' => ['message' => $text('embedMessage'), 'accept' => $text('embedAcceptLabel'), 'customise' => $text('customise')]];
    }
}
