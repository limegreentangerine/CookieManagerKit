<?php

namespace CookieManagerKit\Command\Task;

use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Entity\Settings;
use CookieManagerKit\Support\ErrorLog;
use Doctrine\ORM\EntityManagerInterface;
use Concrete\Core\Command\Task\Output\{OutputAwareInterface, OutputAwareTrait};
use CookieManagerKit\Scan\{CrawlAlreadyRunning, CrawlRunner, CrawlTarget, ScanRuns, Services, StaleCheck, UnsafeUrl};

/**
 * Runs from the command line or cron, so there is no web request: the target host comes from the configured canonical
 * URL only (see CrawlTarget). Output is counts and fixed sentences. It never includes a scanned name or host, because
 * those come from the pages being scanned. A failed crawl throws a fixed-message exception, which Concrete records as
 * the task's failure message; the detail goes to the cookie_manager log.
 */
class CrawlCommandHandler implements OutputAwareInterface
{
    use OutputAwareTrait;

    public const FAILED = 'The crawl could not finish. Details are in the cookie_manager log.';

    public function __invoke(CrawlCommand $command)
    {
        $settings = $this->settings();

        if (!$settings->isScanScheduleEnabled()) {
            $this->output->write(t('Skipped: scheduled scanning is switched off in the Scanner settings, so nothing was scanned.'));

            return;
        }

        try {
            $this->baseUrl();
        } catch (UnsafeUrl) {
            $this->output->write(t('Skipped: the site has no canonical URL, so the scanner does not know which host to crawl.'));

            return;
        }

        try {
            @set_time_limit(300);
            $run = $this->crawl();
        } catch (CrawlAlreadyRunning) {
            $this->output->write(t('Skipped: %s', t(CrawlRunner::ALREADY_RUNNING)));

            return;
        } catch (\Throwable $e) {
            ErrorLog::record($e);

            throw new \RuntimeException(t(self::FAILED));
        }

        if ($run->getStatus() !== ScanRun::COMPLETE) {
            throw new \RuntimeException(t(self::FAILED));
        }

        $this->output->write(t('Crawled %1$s pages: %2$s new, %3$s changed, %4$s unclassified.', $run->getPagesScanned(), $run->getNewCount(), $run->getChangedCount(), $run->getUnclassifiedCount()));

        // The crawl itself already emails the alert (with the same stale flag) when something new appeared; this only reports it.
        if (StaleCheck::isStale($this->lastRuntimeScanAt(), time(), $settings->getScanStaleDays())) {
            $this->output->write(t('No runtime scan in the last %s days. Start scan mode in the dashboard and browse the site.', $settings->getScanStaleDays()));
        }
    }

    protected function settings(): Settings
    {
        return Settings::get();
    }

    protected function baseUrl(): string
    {
        return CrawlTarget::baseUrl();
    }

    protected function crawl(): ScanRun
    {
        return Services::runCrawl();
    }

    protected function lastRuntimeScanAt(): ?int
    {
        return ScanRuns::lastBeaconAt(app(EntityManagerInterface::class));
    }
}
