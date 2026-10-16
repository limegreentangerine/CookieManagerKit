<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Entity\ScanFinding;

/**
 * The only code that writes findings. A finding is identified by (kind, name, host). First sight stores it with a
 * suggestion (or as unclassified). Later sightings update when it was last seen and who saw it, and re-classify it
 * only while it is still unreviewed. A complete crawl removes the crawl as a source of findings it no longer sees;
 * a finding with no source left becomes `gone` (and remembers what it was, so it can come back).
 */
final class FindingIngestor
{
    private const MAX_NEW_ITEMS = 20;

    public function __construct(
        private readonly FindingStore $store,
        private readonly Classifier $classifier,
    ) {}

    private function classify(ScanFinding $finding): void
    {
        $suggestion = $this->classifier->classify($finding->getKind(), $finding->getName(), $finding->getHost());

        $finding->setSuggestion($suggestion?->category, $suggestion?->vendor);
        $finding->setStatus($suggestion === null ? ScanFinding::UNCLASSIFIED : ScanFinding::MATCHED);
    }

    /**
     * @param list<Finding> $found
     * @param bool          $markGone only honoured for a crawl: true when every page was fetched and nothing was excluded or cut off
     */
    public function ingest(array $found, string $source, int $now, bool $markGone): IngestSummary
    {
        $existing = $this->store->all();
        $seen = [];
        $new = 0;
        $changed = 0;
        $newItems = [];

        foreach ($found as $item) {
            // identify() caps name and host before hashing, so the fingerprint must come from the same capped values.
            $candidate = (new ScanFinding())->identify($item->kind, $item->name, $item->host);
            $fingerprint = $candidate->getFingerprint();
            $seen[$fingerprint] = true;
            $finding = $existing[$fingerprint] ?? null;

            if ($finding === null) {
                $finding = $candidate;
                $finding->setFirstSeen($now)->setExamplePage(Clean::name($item->page, 512) ?? '')->addSource($source)->setLastSeen($now);
                $this->classify($finding);
                $this->store->add($finding);
                $existing[$fingerprint] = $finding;
                $new++;

                if (count($newItems) < self::MAX_NEW_ITEMS) {
                    $newItems[] = ['kind' => $finding->getKind(), 'name' => $finding->getName(), 'host' => $finding->getHost()];
                }

                continue;
            }

            if ($finding->getStatus() === ScanFinding::GONE) {
                $finding->restore();
                $changed++;
            }

            $finding->addSource($source)->setLastSeen($now);

            if ($finding->getExamplePage() === '') {
                $finding->setExamplePage(Clean::name($item->page, 512) ?? '');
            }

            if ($finding->isReviewable()) {
                $this->classify($finding);
            }
        }

        if ($markGone && $source === ScanRun::CRAWL) {
            foreach ($existing as $fingerprint => $finding) {
                if (isset($seen[$fingerprint]) || !$finding->hasSource('crawl')) {
                    continue;
                }

                $finding->removeSource('crawl');

                if ($finding->sources() === [] && $finding->getStatus() !== ScanFinding::GONE) {
                    $finding->markGone();
                    $changed++;
                }
            }
        }

        $this->store->flush();

        $unclassified = count(array_filter(
            $existing,
            static fn(ScanFinding $finding): bool => $finding->getStatus() === ScanFinding::UNCLASSIFIED,
        ));

        return new IngestSummary($new, $changed, $unclassified, $newItems);
    }
}
