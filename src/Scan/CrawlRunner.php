<?php

namespace CookieManagerKit\Scan;

use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Entity\Settings;
use CookieManagerKit\Support\ErrorLog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One crawl, start to finish: pick the pages, scan them, ingest the findings, record the run, and alert.
 *
 * Findings may only go `gone` when the crawl saw everything. markGone is true only when ALL of these hold, and each
 * one is a different way for a partial crawl to look like a clean one:
 *  - the scanner says it is complete (no failed, redirected or refused page, time budget not hit, something scanned);
 *  - it scanned exactly the pages it was given (a scanner that under-reports is not trusted);
 *  - there was at least one page;
 *  - the page list was not cut off by the cap, and no page was excluded by the admin's patterns;
 *  - no URL from the page source was dropped for being on another host. Off-host URLs are removed before selection
 *    so they cannot be fetched or eat the cap, but a source that returned one is not trusted to have listed the
 *    site's own pages completely, so such a crawl never marks anything gone. (PublishedPages rebases every URL onto
 *    the canonical host, so with the real source this never triggers.)
 *
 * Members-only pages answer the anonymous crawler with a 403 or a redirect, so they count as failed pages and the crawl is
 * incomplete (sawEverything false, nothing goes `gone`) until they are added to the exclude list.
 *
 * Overlap: run() first sweeps crawl runs abandoned in `running` (ScanRuns::sweepStaleCrawls) and throws
 * CrawlAlreadyRunning, before recording anything, if a non-stale crawl is still running. The scheduled task and the
 * dashboard's "run now" should catch it and show its fixed message (ALREADY_RUNNING).
 *
 * Failure handling: an exception from the scanner or the ingest marks the run failed. Ingest can raise a unique
 * constraint race or a database error, either of which closes the EntityManager, so the failure is recorded through
 * the DBAL connection and the EntityManager is not used again. Ingest failures are logged and rethrown with a fixed
 * message so database text never reaches the caller.
 */
final class CrawlRunner
{
    public const STORE_FAILED = 'The crawl could not store its findings.';
    public const ALREADY_RUNNING = 'A crawl is already running.';
    public const RECORD_FAILED = 'The crawl could not record its result.';

    /**
     * @param \Closure(): ?int       $lastRuntimeScanAt when the last scan-mode run happened, or null
     * @param (\Closure(): int)|null $clock             defaults to time()
     * @param (\Closure(): int)|null $unclassifiedCount the total of unclassified findings; defaults to a repository count
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly UrlSource $urls,
        private readonly Scanner $scanner,
        private readonly FindingIngestor $ingestor,
        private readonly AlertSender $alerts,
        private readonly string $siteHost,
        private readonly \Closure $lastRuntimeScanAt,
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $unclassifiedCount = null,
    ) {}

    private function now(): int
    {
        return ($this->clock ?? static fn(): int => time())();
    }

    /**
     * Only URLs whose host is the site's own go on to selection.
     *
     * @param list<string> $listed
     *
     * @return array{urls: list<string>, dropped: int}
     */
    private function onSiteHost(array $listed): array
    {
        $kept = [];
        $dropped = 0;

        foreach ($listed as $url) {
            $parts = parse_url((string) $url);
            $host = is_array($parts) ? Host::normalise($parts['host'] ?? null) : null;
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

            if (!is_array($parts) || $host !== $this->siteHost || !in_array($scheme, ['http', 'https'], true)) {
                $dropped++;

                continue;
            }

            // Rebuilt on the normalised host: no credentials, no fragment, no trailing-dot or upper-case host.
            $path = (string) ($parts['path'] ?? '/');
            $kept[] = $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . ($path === '' ? '/' : $path) . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }

        return ['urls' => $kept, 'dropped' => $dropped];
    }

    /**
     * Marks the run failed on the connection, never through the EntityManager (which a failed flush has closed).
     * Never throws; if even this fails the row stays `running`.
     */
    private function fail(ScanRun $run): void
    {
        $run->finish(ScanRun::FAILED, $this->now());

        try {
            $metadata = $this->em->getClassMetadata(ScanRun::class);

            $this->em->getConnection()->update(
                $metadata->getTableName(),
                [$metadata->getColumnName('status') => ScanRun::FAILED, $metadata->getColumnName('finishedAt') => $run->getFinishedAt()],
                [$metadata->getColumnName('id') => $run->getID()],
            );
        } catch (\Throwable $e) {
            ErrorLog::record($e);
        }
    }

    /**
     * Records a run that failed before it could start (for example no usable canonical URL), so the dashboard shows it.
     * Never throws.
     */
    public static function recordFailure(EntityManagerInterface $em, int $now, \Throwable $reason): ScanRun
    {
        $run = (new ScanRun())->start(ScanRun::CRAWL, $now)->finish(ScanRun::FAILED, $now);

        ErrorLog::record($reason);

        try {
            $em->persist($run);
            $em->flush();
        } catch (\Throwable $e) {
            ErrorLog::record(new \RuntimeException('The failed crawl run could not be recorded: ' . $e::class));
        }

        return $run;
    }

    /**
     * @throws \Throwable whatever the scanner raised, after the run has been marked failed; a fixed-message
     *                    RuntimeException when the findings or the result could not be stored
     */
    public function run(): ScanRun
    {
        ScanRuns::sweepStaleCrawls($this->em, $this->now());

        if (ScanRuns::crawlIsRunning($this->em, $this->now())) {
            throw new CrawlAlreadyRunning(self::ALREADY_RUNNING);
        }

        $run = (new ScanRun())->start(ScanRun::CRAWL, $this->now());
        $this->em->persist($run);
        $this->em->flush();

        try {
            $listed = $this->onSiteHost($this->urls->all());
            $listed['dropped'] += $this->urls->dropped();
            $selected = UrlSelector::select($listed['urls'], $this->settings->getScanExcludes(), $this->settings->getScanMaxPages());
            $result = $this->scanner->scan(new ScanContext($this->siteHost, $selected['urls']));
        } catch (\Throwable $e) {
            $this->fail($run);

            throw $e;
        }

        $sawEverything = $result->isComplete()
            && $selected['urls'] !== []
            && $result->pagesScanned === count($selected['urls'])
            && !$selected['truncated']
            && $selected['excluded'] === 0
            && $listed['dropped'] === 0;

        try {
            $summary = $this->ingestor->ingest($result->findings, ScanRun::CRAWL, $this->now(), $sawEverything);
        } catch (\Throwable $e) {
            ErrorLog::record(new \RuntimeException(self::STORE_FAILED . ' ' . $e::class));
            $this->fail($run);

            throw new \RuntimeException(self::STORE_FAILED);
        }

        try {
            $unclassified = ($this->unclassifiedCount ?? fn(): int => FindingCounts::unclassified($this->em))();
        } catch (\Throwable) {
            $unclassified = $summary->unclassified;
        }

        $summary = new IngestSummary($summary->new, $summary->changed, $unclassified, $summary->newItems);

        try {
            $run->setPagesScanned($result->pagesScanned)
                ->setCounts($summary->new, $summary->changed, $summary->unclassified)
                ->setSawEverything($sawEverything)
                ->finish(ScanRun::COMPLETE, $this->now());
            $this->em->flush();
        } catch (\Throwable $e) {
            ErrorLog::record(new \RuntimeException(self::RECORD_FAILED . ' ' . $e::class));
            $this->fail($run);

            throw new \RuntimeException(self::RECORD_FAILED);
        }

        if ($summary->new > 0 || $summary->changed > 0) {
            try {
                $stale = StaleCheck::isStale(($this->lastRuntimeScanAt)(), $this->now(), $this->settings->getScanStaleDays());

                $this->alerts->send($run, $summary, $stale);
            } catch (\Throwable $e) {
                ErrorLog::record($e);
            }
        }

        return $run;
    }
}
