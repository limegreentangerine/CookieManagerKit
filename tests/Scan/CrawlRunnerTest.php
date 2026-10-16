<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests\Scan;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use CookieManagerKit\Scan\Finding;
use CookieManagerKit\Scan\Scanner;
use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Scan\UrlSource;
use CookieManagerKit\Entity\Settings;
use CookieManagerKit\Scan\Classifier;
use CookieManagerKit\Scan\ScanResult;
use CookieManagerKit\Scan\CrawlRunner;
use CookieManagerKit\Scan\ScanContext;
use CookieManagerKit\Scan\FindingStore;
use CookieManagerKit\Entity\ScanFinding;
use Doctrine\ORM\EntityManagerInterface;
use CookieManagerKit\Scan\FindingIngestor;

final class CrawlRunnerTest extends TestCase
{
    private InMemoryFindingStore $store;

    private RecordingAlerts $alerts;

    private function found(string $kind, string $name, string $host): ScanFinding
    {
        $finding = $this->store->get($kind, $name, $host);

        $this->assertNotNull($finding, 'finding ' . $kind . ' ' . $name . ' was not stored');

        return $finding;
    }

    /**
     * @param list<string>          $pages
     * @param ScanResult|\Throwable $result
     */
    private function runner(array $pages, ScanResult|\Throwable $result, ?Settings $settings = null, ?int $lastRuntime = 999990, ?EntityManagerInterface $em = null, ?FindingStore $store = null): CrawlRunner
    {
        $urls = new class($pages) implements UrlSource {
            public function __construct(private array $pages) {}

            public function all(): array
            {
                return $this->pages;
            }
        };
        $scanner = new class($result) implements Scanner {
            public ?ScanContext $context = null;

            public function __construct(private ScanResult|\Throwable $result) {}

            public function scan(ScanContext $context): ScanResult
            {
                $this->context = $context;

                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        };
        return new CrawlRunner(
            $em ?? $this->createMock(EntityManagerInterface::class),
            $settings ?? new Settings(),
            $urls,
            $scanner,
            new FindingIngestor($store ?? $this->store, new Classifier([], static fn(string $name): bool => false)),
            $this->alerts,
            'e.com',
            static fn(): ?int => $lastRuntime,
            static fn(): int => 1000000,
            static fn(): int => 42,
        );
    }

    /**
     * An EntityManager double whose persist() gives the entity an id, as a real flush would.
     */
    private function emAssigningIds(): EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity): void {
            (new \ReflectionProperty($entity, 'id'))->setValue($entity, 1);
        });

        $metadata = $this->createMock(\Doctrine\ORM\Mapping\ClassMetadata::class);
        $metadata->method('getTableName')->willReturn('cmkScanRuns');
        $metadata->method('getColumnName')->willReturnArgument(0);
        $em->method('getClassMetadata')->willReturn($metadata);

        return $em;
    }

    private static function failingStore(string $message): FindingStore
    {
        return new class($message) implements FindingStore {
            public function __construct(private readonly string $message) {}

            public function all(): array
            {
                return [];
            }

            public function findByFingerprints(array $fingerprints): array
            {
                return [];
            }

            public function add(ScanFinding $finding): void {}

            public function flush(): void
            {
                throw new \RuntimeException($this->message);
            }
        };
    }

    private static function cookie(string $name): Finding
    {
        return new Finding('cookie', $name, 'e.com', 'https://e.com/');
    }

    public function testACompleteCrawlStoresFindingsCompletesTheRunAndAlerts(): void
    {
        $run = $this->runner(['https://e.com/'], new ScanResult([self::cookie('a')], 1, 0))->run();

        $this->assertSame(ScanRun::COMPLETE, $run->getStatus());
        $this->assertSame(ScanRun::CRAWL, $run->getSource());
        $this->assertSame(1000000, $run->getStartedAt());
        $this->assertSame(1000000, $run->getFinishedAt());
        $this->assertSame([1, 1], [$run->getPagesScanned(), $run->getNewCount()]);
        $this->assertNotNull($this->store->get('cookie', 'a', 'e.com'));
        $this->assertCount(1, $this->alerts->sent);
        $this->assertFalse($this->alerts->sent[0][2]);
    }

    public function testTheRunAndTheAlertCarryTheGlobalUnclassifiedCountNotTheIngestOne(): void
    {
        $run = $this->runner(['https://e.com/'], new ScanResult([self::cookie('a')], 1, 0))->run();

        $this->assertSame(42, $run->getUnclassifiedCount());
        $this->assertSame(42, $this->alerts->sent[0][1]->unclassified);
    }

    public function testTheScannerIsToldTheSiteHostAndTheSelectedPages(): void
    {
        $settings = (new Settings())->setScanMaxPages(2)->setScanExcludesText('/private');
        $runner = $this->runner(['https://e.com/a', 'https://e.com/private', 'https://e.com/b', 'https://e.com/c'], new ScanResult([], 2, 0), $settings);

        $runner->run();

        $reflection = new \ReflectionProperty($runner, 'scanner');
        $context = $reflection->getValue($runner)->context;

        $this->assertSame('e.com', $context->siteHost);
        $this->assertSame(['https://e.com/a', 'https://e.com/b'], $context->urls);
    }

    public function testOffHostUrlsFromThePageSourceAreNeverHandedToTheScanner(): void
    {
        $runner = $this->runner(['https://e.com/a', 'https://evil.example.net/x', 'http://', 'https://E.COM./b'], new ScanResult([], 2, 0));

        $runner->run();

        $context = (new \ReflectionProperty($runner, 'scanner'))->getValue($runner)->context;

        $this->assertSame(['https://e.com/a', 'https://E.COM./b'], $context->urls);
    }

    public function testACompleteUntruncatedUnexcludedCrawlMarksVanishedFindingsGone(): void
    {
        $this->store->add((new ScanFinding())->identify('cookie', 'old', 'e.com')->addSource('crawl')->setStatus(ScanFinding::UNCLASSIFIED));

        $run = $this->runner(['https://e.com/'], new ScanResult([], 1, 0))->run();

        $this->assertSame(ScanFinding::GONE, $this->found('cookie', 'old', 'e.com')->getStatus());
        $this->assertSame(1, $run->getChangedCount());
    }

    public function testAFailedPageMeansNothingIsMarkedGone(): void
    {
        $this->store->add((new ScanFinding())->identify('cookie', 'old', 'e.com')->addSource('crawl'));

        $this->runner(['https://e.com/', 'https://e.com/broken'], new ScanResult([], 1, 1))->run();

        $this->assertNotSame(ScanFinding::GONE, $this->found('cookie', 'old', 'e.com')->getStatus());
    }

    public function testATruncatedPageListMeansNothingIsMarkedGone(): void
    {
        $this->store->add((new ScanFinding())->identify('cookie', 'old', 'e.com')->addSource('crawl'));
        $settings = (new Settings())->setScanMaxPages(1);

        $this->runner(['https://e.com/a', 'https://e.com/b'], new ScanResult([], 1, 0), $settings)->run();

        $this->assertNotSame(ScanFinding::GONE, $this->found('cookie', 'old', 'e.com')->getStatus());
    }

    public function testExcludedPagesMeanNothingIsMarkedGone(): void
    {
        $this->store->add((new ScanFinding())->identify('cookie', 'old', 'e.com')->addSource('crawl'));
        $settings = (new Settings())->setScanExcludesText('/members');

        $this->runner(['https://e.com/a', 'https://e.com/members'], new ScanResult([], 1, 0), $settings)->run();

        $this->assertNotSame(ScanFinding::GONE, $this->found('cookie', 'old', 'e.com')->getStatus());
    }

    public function testNothingNewOrChangedSendsNoAlert(): void
    {
        $this->runner(['https://e.com/'], new ScanResult([], 1, 0))->run();

        $this->assertSame([], $this->alerts->sent);
    }

    public function testAStaleRuntimeScanIsPassedToTheAlert(): void
    {
        $stale = $this->runner(['https://e.com/'], new ScanResult([self::cookie('a')], 1, 0), null, null)->run();

        $this->assertTrue($this->alerts->sent[0][2]);
        $this->assertSame(ScanRun::COMPLETE, $stale->getStatus());
    }

    public function testAScannerThatThrowsFailsTheRunAndRethrows(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('update');
        $em = $this->emAssigningIds();
        $em->method('getConnection')->willReturn($connection);
        $runner = $this->runner(['https://e.com/'], new \RuntimeException('boom'), null, 999990, $em);

        try {
            $runner->run();
            $this->fail('swallowed the failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame([], $this->alerts->sent);
        $this->assertSame([], $this->store->findings);
    }

    public function testAnIngestFailureFailsTheRunWithAFixedMessageAndNeverReusesTheEntityManager(): void
    {
        $store = self::failingStore("SQLSTATE[23000]: Duplicate entry 'secret-row' for key 'fingerprint'");
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('update')->with('cmkScanRuns', ['status' => ScanRun::FAILED, 'finishedAt' => 1000000], ['id' => 1]);
        $em = $this->emAssigningIds();
        $em->method('getConnection')->willReturn($connection);
        // Only the flush that starts the run: the EntityManager is never flushed again after the ingest failed.
        $em->expects($this->once())->method('flush');
        $runner = $this->runner(['https://e.com/'], new ScanResult([self::cookie('a')], 1, 0), null, 999990, $em, $store);
        $caught = null;

        try {
            $runner->run();
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame(CrawlRunner::STORE_FAILED, $caught->getMessage());
        $this->assertNull($caught->getPrevious());
        $this->assertSame([], $this->alerts->sent);
    }

    public function testAnIngestFailureLeavesTheRunMarkedFailedNotComplete(): void
    {
        $store = self::failingStore('closed');
        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $runner = $this->runner(['https://e.com/'], new ScanResult([self::cookie('a')], 1, 0), null, 999990, $em, $store);

        try {
            $runner->run();
        } catch (\RuntimeException) {
        }

        $this->assertCount(1, $persisted);
        $this->assertSame(ScanRun::FAILED, $persisted[0]->getStatus());
        $this->assertSame(1000000, $persisted[0]->getFinishedAt());
    }

    public function testAFailureToRecordTheResultIsAFixedMessageToo(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $calls = 0;
        $em->method('flush')->willReturnCallback(static function () use (&$calls): void {
            if (++$calls === 2) {
                throw new \RuntimeException('connection lost: password=hunter2');
            }
        });
        $runner = $this->runner(['https://e.com/'], new ScanResult([self::cookie('a')], 1, 0), null, 999990, $em);

        $this->expectExceptionMessage(CrawlRunner::RECORD_FAILED);

        $runner->run();
    }

    public function testRecordFailureStoresAFailedCrawlRunAndNeverThrows(): void
    {
        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $em->method('flush')->willThrowException(new \RuntimeException('db down'));

        $run = CrawlRunner::recordFailure($em, 500, new \RuntimeException('no canonical'));

        $this->assertSame(ScanRun::FAILED, $run->getStatus());
        $this->assertSame(ScanRun::CRAWL, $run->getSource());
        $this->assertSame(500, $run->getFinishedAt());
        $this->assertSame([$run], $persisted);
    }

    protected function setUp(): void
    {
        $this->store = new InMemoryFindingStore();
        $this->alerts = new RecordingAlerts();
    }
}
