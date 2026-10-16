<?php

namespace CookieManagerKit\Scan;

use GuzzleHttp\Client;
use CookieManagerKit\Entity\ScanRun;
use Concrete\Core\Support\Facade\Url;
use CookieManagerKit\Entity\Settings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the scanner's collaborators from Concrete's container. Everything with logic lives in the classes this
 * wires together; this class only chooses the real implementations.
 *
 * Local development sites: the crawler refuses loopback and private addresses by default, so a site served from
 * localhost or a LAN address cannot be crawled. Set the config key `cookie_manager_kit.scan.allow_private_network`
 * to true (for example in application/config/cookie_manager_kit.php, under the `scan` key) to allow loopback and
 * RFC 1918 addresses only; link-local (cloud metadata), multicast and reserved ranges stay refused. Leave it unset
 * (false) in production. The flag is read in exactly one place, allowPrivateNetwork() below.
 *
 * The production fetcher is built without GuzzleFetcher's test-only `allowUnpinnedTransportForTests` argument, so the
 * address pin can never be switched off from here.
 */
final class Services
{
    public const PRIVATE_NETWORK_KEY = 'cookie_manager_kit.scan.allow_private_network';

    private static function em(): EntityManagerInterface
    {
        return app(EntityManagerInterface::class);
    }

    private static function allowPrivateNetwork(): bool
    {
        return filter_var(app('config')->get(self::PRIVATE_NETWORK_KEY, false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function classifier(): Classifier
    {
        return new Classifier((new ClassificationStore(self::em()))->entries());
    }

    public static function ingestor(): FindingIngestor
    {
        return new FindingIngestor(new DoctrineFindingStore(self::em()), self::classifier());
    }

    /**
     * @throws UnsafeUrl when the site's host cannot be scanned (no canonical URL, not resolvable, refused address)
     */
    public static function crawlRunner(): CrawlRunner
    {
        $em = self::em();
        $settings = Settings::get();
        $baseUrl = CrawlTarget::baseUrl();
        $guard = UrlGuard::forSite($baseUrl, null, self::allowPrivateNetwork());
        $siteHost = $guard->host();

        return new CrawlRunner(
            $em,
            $settings,
            new PublishedPages(CrawlTarget::origin($baseUrl, $guard)),
            new CrawlScanner(new GuzzleFetcher(new Client(), $guard)),
            self::ingestor(),
            new MailAlerts($settings, $siteHost, (string) Url::to('/dashboard/cookie_manager/findings')),
            $siteHost,
            static fn(): ?int => ScanRuns::lastBeaconAt($em),
        );
    }

    /**
     * A crawl that always yields a run: when the crawl cannot even start (no canonical URL, host not scannable) a
     * failed run is recorded and returned instead of an exception. Callers must check Settings before refreshing
     * classification data themselves; nothing here refreshes it.
     */
    public static function runCrawl(): ScanRun
    {
        try {
            $runner = self::crawlRunner();
        } catch (UnsafeUrl $e) {
            return CrawlRunner::recordFailure(self::em(), time(), $e);
        }

        return $runner->run();
    }
}
