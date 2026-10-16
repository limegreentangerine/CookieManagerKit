<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanFinding;
use CookieManagerKit\Support\EssentialCookies;

/**
 * Suggests a category for a finding. Precedence: ConcreteCMS's own essential cookies first (they are always
 * necessary, so no data and no override can reclassify them), then override, refreshed and bundled entries in that
 * order; inside an origin an exact pattern beats a wildcard and a longer pattern beats a shorter one.
 */
final class Classifier
{
    private const RANK = ['override' => 0, 'refreshed' => 1, 'bundled' => 2];

    /** @var list<array{kind: string, pattern: string, category: string, vendor: string, origin: string}> */
    private array $entries;

    private \Closure $isEssential;

    /**
     * @param list<array{kind: string, pattern: string, category: string, vendor: string, origin: string}> $entries
     * @param (callable(string): bool)|null                                                                $isEssential defaults to the live Concrete cookie list
     */
    public function __construct(array $entries, ?callable $isEssential = null)
    {
        $valid = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            foreach (['kind', 'pattern', 'category', 'vendor', 'origin'] as $key) {
                if (!isset($entry[$key]) || !is_string($entry[$key])) {
                    continue 2;
                }
            }

            $valid[] = $entry;
        }

        $entries = $valid;

        $key = static fn(array $e): array => [
            self::RANK[$e['origin']] ?? 9,
            $e['kind'] === 'storage' ? 0 : 1,
            str_contains($e['pattern'], '*') ? 1 : 0,
            -strlen($e['pattern']),
        ];

        usort($entries, static fn(array $a, array $b): int => $key($a) <=> $key($b));

        $this->entries = $entries;
        $this->isEssential = $isEssential !== null
            ? \Closure::fromCallable($isEssential)
            : static fn(string $name): bool => EssentialCookies::isEssential($name);
    }

    public function classify(string $kind, string $name, string $host): ?Suggestion
    {
        $byHost = !in_array($kind, [ScanFinding::COOKIE, ScanFinding::STORAGE], true);

        if (!$byHost && ($this->isEssential)($name)) {
            return new Suggestion('necessary', 'ConcreteCMS');
        }

        $kinds = match ($kind) {
            ScanFinding::COOKIE => ['cookie'],
            ScanFinding::STORAGE => ['storage', 'cookie'],
            default => ['host'],
        };

        foreach ($this->entries as $entry) {
            if (!in_array($entry['kind'], $kinds, true)) {
                continue;
            }

            $matches = $byHost ? Pattern::matchesHost($entry['pattern'], $host) : Pattern::matches($entry['pattern'], $name);

            if ($matches) {
                return new Suggestion($entry['category'], $entry['vendor']);
            }
        }

        return null;
    }
}
