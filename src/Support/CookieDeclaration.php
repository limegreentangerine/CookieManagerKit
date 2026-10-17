<?php

namespace CookieManagerKit\Support;

/**
 * Pure: turns the cached snapshot into the sections the cookie declaration block prints. Fallback is per field:
 * the visitor's locale, then the site default locale, then nothing. Nothing here depends on the visitor.
 */
final class CookieDeclaration
{
    /**
     * @param array<string, mixed> $byLocale locale => string
     */
    private static function pick(array $byLocale, string $locale, string $default): string
    {
        foreach ([$locale, $default] as $candidate) {
            $value = $byLocale[$candidate] ?? '';

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $byLocale locale => ['title' => ..., 'description' => ...]
     */
    private static function pickField(array $byLocale, string $locale, string $default, string $key): string
    {
        $values = [];

        foreach ($byLocale as $candidate => $row) {
            $values[$candidate] = is_array($row) ? ($row[$key] ?? '') : '';
        }

        return self::pick($values, $locale, $default);
    }
    /**
     * @param array<string, mixed>                                         $snapshot
     * @param list<array{name: string, purpose: string, duration: string}> $essential
     * @param callable(int): string                                        $duration
     *
     * @return list<array{handle: string, title: string, description: string, cookies: list<array{name: string, provider: string, purpose: string, duration: string}>}>
     */
    public static function build(array $snapshot, string $locale, string $default, array $essential, callable $duration): array
    {
        $sections = [];

        foreach (is_array($snapshot['categories'] ?? null) ? $snapshot['categories'] : [] as $category) {
            $handle = is_array($category) ? ($category['handle'] ?? null) : null;

            if (!Text::isHandle($handle)) {
                continue;
            }

            $content = is_array($category['content'] ?? null) ? $category['content'] : [];
            $rows = [];

            if (($category['necessary'] ?? false) === true) {
                foreach ($essential as $cookie) {
                    $rows[] = [
                        'name' => (string) ($cookie['name'] ?? ''),
                        'provider' => '',
                        'purpose' => (string) ($cookie['purpose'] ?? ''),
                        'duration' => (string) ($cookie['duration'] ?? ''),
                    ];
                }
            }

            foreach (is_array($category['services'] ?? null) ? $category['services'] : [] as $service) {
                if (!is_array($service)) {
                    continue;
                }

                $provider = (string) (($service['vendor'] ?? '') !== '' ? $service['vendor'] : ($service['name'] ?? ''));

                foreach (is_array($service['cookies'] ?? null) ? $service['cookies'] : [] as $cookie) {
                    $name = is_array($cookie) ? ($cookie['name'] ?? '') : '';

                    if (!is_string($name) || $name === '') {
                        continue;
                    }

                    $rows[] = [
                        'name' => $name,
                        'provider' => $provider,
                        'purpose' => self::pick(is_array($cookie['content'] ?? null) ? $cookie['content'] : [], $locale, $default),
                        'duration' => $duration(max(0, (int) ($cookie['days'] ?? 0))),
                    ];
                }
            }

            if ($rows === []) {
                continue;
            }

            $sections[] = [
                'handle' => $handle,
                'title' => self::pickField($content, $locale, $default, 'title') ?: $handle,
                'description' => self::pickField($content, $locale, $default, 'description'),
                'cookies' => $rows,
            ];
        }

        return $sections;
    }
}
