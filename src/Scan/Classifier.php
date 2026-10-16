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
        $entries = array_values($entries);

        usort($entries, static function (array $a, array $b): int {
            return [self::RANK[$a['origin']] ?? 9, str_contains($a['pattern'], '*') ? 1 : 0, -strlen($a['pattern'])]
                <=> [self::RANK[$b['origin']] ?? 9, str_contains($b['pattern'], '*') ? 1 : 0, -strlen($b['pattern'])];
        });

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
