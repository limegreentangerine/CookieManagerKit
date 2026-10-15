<?php

namespace CookieManagerKit\Support;

/**
 * Pure: turns the plain-data snapshot into the config document the runtime renders. No Concrete, no database.
 * Fallback is per field: the visitor's locale, then the site default locale, then built-in English text.
 */
final class ConfigBuilder
{
    /**
     * One field from a per-locale map: own locale, then the default locale, then the fallback. Empty means "not set".
     *
     * @param array<string, mixed> $byLocale
     */
    private static function field(array $byLocale, string $locale, string $defaultLocale, string $key, string $fallback): string
    {
        foreach ([$locale, $defaultLocale] as $candidate) {
            $row = $byLocale[$candidate] ?? null;
            $value = is_array($row) ? ($row[$key] ?? '') : '';

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $byLocale locale => string
     */
    private static function scalar(array $byLocale, string $locale, string $defaultLocale): string
    {
        foreach ([$locale, $defaultLocale] as $candidate) {
            $value = $byLocale[$candidate] ?? '';

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<int, mixed> $categories
     *
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $categories): array
    {
        $rows = array_values(array_filter($categories, 'is_array'));

        usort($rows, static function (array $a, array $b): int {
            return [(int) ($a['sort'] ?? 0), (string) ($a['handle'] ?? '')] <=> [(int) ($b['sort'] ?? 0), (string) ($b['handle'] ?? '')];
        });

        return $rows;
    }
    /**
     * @param array<string, mixed> $data
     * @param list<string>         $essentialCookies
     *
     * @return array<string, mixed>
     */
    public static function build(array $data, string $locale, string $defaultLocale, array $essentialCookies): array
    {
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $defaults = is_array($data['defaults'] ?? null) ? $data['defaults'] : [];
        $banner = is_array($data['banner'] ?? null) ? $data['banner'] : [];

        $text = static fn(string $key): string => self::field($banner, $locale, $defaultLocale, $key, (string) ($defaults[$key] ?? ''));

        $categories = [];

        foreach (self::sorted(is_array($data['categories'] ?? null) ? $data['categories'] : []) as $category) {
            $handle = $category['handle'] ?? null;

            if (!Text::isHandle($handle)) {
                continue;
            }

            $content = is_array($category['content'] ?? null) ? $category['content'] : [];
            $necessary = (bool) ($category['necessary'] ?? false);
            $services = [];

            foreach (is_array($category['services'] ?? null) ? $category['services'] : [] as $service) {
                $descriptions = is_array($service['content'] ?? null) ? $service['content'] : [];

                $services[] = [
                    'name' => (string) ($service['name'] ?? ''),
                    'vendor' => (string) ($service['vendor'] ?? ''),
                    'description' => self::scalar($descriptions, $locale, $defaultLocale),
                ];
            }

            $categories[] = [
                'handle' => $handle,
                'necessary' => $necessary,
                'title' => self::field($content, $locale, $defaultLocale, 'title', $handle),
                'description' => self::field($content, $locale, $defaultLocale, 'description', ''),
                'services' => $services,
                'cookies' => $necessary ? array_values($essentialCookies) : [],
            ];
        }

        $tokens = [];
        $savedTokens = is_array($settings['tokens'] ?? null) ? $settings['tokens'] : [];

        foreach (StyleRules::DEFAULT_TOKENS as $name => $fallback) {
            $tokens[$name] = StyleRules::hex($savedTokens[$name] ?? null, $fallback);
        }

        $config = [
            'policyVersion' => max(1, (int) ($settings['policyVersion'] ?? 1)),
            'locale' => $locale,
            'banner' => [
                'title' => $text('title'),
                'body' => $text('body'),
                'labels' => [
                    'acceptAll' => $text('acceptAll'),
                    'rejectAll' => $text('rejectAll'),
                    'customise' => $text('customise'),
                    'save' => $text('save'),
                    'widget' => $text('widget'),
                ],
                'policyUrl' => Output::safeUrl(self::field($banner, $locale, $defaultLocale, 'policyUrl', '')),
                'policyLinkText' => $text('policyLinkText'),
            ],
            'categories' => $categories,
            'style' => [
                'position' => StyleRules::choice($settings['position'] ?? null, StyleRules::POSITIONS, 'bottom-right'),
                'layout' => StyleRules::choice($settings['layout'] ?? null, StyleRules::LAYOUTS, 'stacked'),
                'font' => StyleRules::choice($settings['font'] ?? null, StyleRules::FONTS, 'inherit'),
                'tokens' => $tokens,
                'radius' => StyleRules::radius($settings['radius'] ?? 8),
                'shadow' => (bool) ($settings['shadow'] ?? true),
            ],
            'lifetimeDays' => StyleRules::lifetime($settings['lifetimeDays'] ?? null),
            'widget' => (bool) ($settings['widget'] ?? true),
        ];

        return ['version' => substr(sha1(Output::json($config)), 0, 12)] + $config;
    }
}
