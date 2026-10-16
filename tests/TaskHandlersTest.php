<?php

declare(strict_types=1);

namespace CookieManagerKit\Tests;

use PHPUnit\Framework\TestCase;
use CookieManagerKit\Entity\ScanRun;
use CookieManagerKit\Entity\Settings;
use Concrete\Core\Command\Task\Output\OutputInterface;
use CookieManagerKit\Scan\{CrawlAlreadyRunning, CrawlRunner, Refresher, UnsafeUrl};
use CookieManagerKit\Command\Task\{CrawlCommand, CrawlCommandHandler, RefreshClassificationCommand, RefreshClassificationCommandHandler};

final class TaskHandlersTest extends TestCase
{
    private static function sink(): object
    {
        return new class implements OutputInterface {
            /** @var list<string> */
            public array $lines = [];

            public function write($message): void
            {
                $this->lines[] = (string) $message;
            }

            public function writeError($message): void
            {
                $this->lines[] = (string) $message;
            }
        };
    }

    private static function crawl(Settings $settings, \Closure $crawl, ?\Closure $baseUrl = null, ?int $lastScan = null): array
    {
        $handler = new class($settings, $crawl, $baseUrl ?? static fn(): string => 'https://e.com', $lastScan) extends CrawlCommandHandler {
            public int $calls = 0;

            public function __construct(private Settings $s, private \Closure $crawlFn, private \Closure $baseFn, private ?int $last) {}

            protected function settings(): Settings
            {
                return $this->s;
            }

            protected function baseUrl(): string
            {
                return ($this->baseFn)();
            }

            protected function crawl(): ScanRun
            {
                $this->calls++;

                return ($this->crawlFn)();
            }

            protected function lastRuntimeScanAt(): ?int
            {
                return $this->last;
            }
        };
        $output = self::sink();
        $handler->setOutput($output);

        return [$handler, $output];
    }

    private static function completeRun(): ScanRun
    {
        return (new ScanRun())->start(ScanRun::CRAWL, 10)->setPagesScanned(4)->setCounts(2, 1, 3)->finish(ScanRun::COMPLETE, 20);
    }

    private function refresh(Settings $settings, ?\Closure $refresh): array
    {
        $handler = new class($settings, $refresh, $this->createMock(\CookieManagerKit\Scan\ClassificationStore::class)) extends RefreshClassificationCommandHandler {
            public int $calls = 0;

            public function __construct(private Settings $s, private ?\Closure $fn, private \CookieManagerKit\Scan\ClassificationStore $store) {}

            protected function settings(): Settings
            {
                return $this->s;
            }

            protected function refresher(): Refresher
            {
                $this->calls++;
                $fn = $this->fn;

                return new Refresher(
                    new class($fn) implements \CookieManagerKit\Scan\SnapshotSource {
                        public function __construct(private \Closure $fn) {}

                        public function fetch(): string
                        {
                            return ($this->fn)();
                        }
                    },
                    $this->store,
                );
            }
        };
        $output = self::sink();
        $handler->setOutput($output);

        return [$handler, $output];
    }

    public function testTheCrawlDoesNothingWhenSchedulingIsOff(): void
    {
        [$handler, $output] = self::crawl((new Settings())->setScanScheduleEnabled(false), static fn() => self::completeRun());

        $handler(new CrawlCommand());

        $this->assertSame(0, $handler->calls);
        $this->assertStringStartsWith('Skipped', $output->lines[0]);
    }

    public function testTheCrawlDoesNothingWithoutACanonicalUrl(): void
    {
        [$handler, $output] = self::crawl((new Settings())->setScanScheduleEnabled(true), static fn() => self::completeRun(), static function (): string {
            throw new UnsafeUrl('x');
        });

        $handler(new CrawlCommand());

        $this->assertSame(0, $handler->calls);
        $this->assertStringStartsWith('Skipped', $output->lines[0]);
    }

    public function testACompleteCrawlReportsCountsAndAStaleRuntimeScan(): void
    {
        [$handler, $output] = self::crawl((new Settings())->setScanScheduleEnabled(true), static fn() => self::completeRun());

        $handler(new CrawlCommand());

        $this->assertSame('Crawled 4 pages: 2 new, 1 changed, 3 unclassified.', $output->lines[0]);
        $this->assertStringContainsString('No runtime scan in the last 60 days', $output->lines[1]);
    }

    public function testARecentRuntimeScanIsNotFlaggedStale(): void
    {
        [$handler, $output] = self::crawl((new Settings())->setScanScheduleEnabled(true), static fn() => self::completeRun(), null, time());

        $handler(new CrawlCommand());

        $this->assertCount(1, $output->lines);
    }

    public function testAnOverlappingCrawlIsSkippedNotFailed(): void
    {
        [$handler, $output] = self::crawl((new Settings())->setScanScheduleEnabled(true), static function (): ScanRun {
            throw new CrawlAlreadyRunning(CrawlRunner::ALREADY_RUNNING);
        });

        $handler(new CrawlCommand());

        $this->assertSame(['Skipped: ' . CrawlRunner::ALREADY_RUNNING], $output->lines);
    }

    public function testAnyOtherFailureThrowsAFixedMessageWithNoExceptionText(): void
    {
        [$handler, $output] = self::crawl((new Settings())->setScanScheduleEnabled(true), static function (): ScanRun {
            throw new \RuntimeException('secret.example.com cookie _evil');
        });

        try {
            $handler(new CrawlCommand());
            $this->fail('expected a failure');
        } catch (\RuntimeException $e) {
            $this->assertSame(CrawlCommandHandler::FAILED, $e->getMessage());
        }

        $this->assertSame([], $output->lines);
    }

    public function testAFailedRunIsAFailedTask(): void
    {
        [$handler] = self::crawl((new Settings())->setScanScheduleEnabled(true), static fn() => (new ScanRun())->start(ScanRun::CRAWL, 1)->finish(ScanRun::FAILED, 2));

        $this->expectExceptionMessage(CrawlCommandHandler::FAILED);

        $handler(new CrawlCommand());
    }

    public function testRefreshDoesNothingWhenDownloadingIsOff(): void
    {
        [$handler, $output] = $this->refresh((new Settings())->setClassificationRefreshEnabled(false), null);

        $handler(new RefreshClassificationCommand());

        $this->assertSame(0, $handler->calls);
        $this->assertStringStartsWith('Skipped', $output->lines[0]);
    }

    public function testRefreshFailureThrowsAFixedMessage(): void
    {
        [$handler] = $this->refresh((new Settings())->setClassificationRefreshEnabled(true), static function (): string {
            throw new \RuntimeException('boom https://leak.example');
        });

        try {
            $handler(new RefreshClassificationCommand());
            $this->fail('expected a failure');
        } catch (\RuntimeException $e) {
            $this->assertSame(RefreshClassificationCommandHandler::FAILED, $e->getMessage());
        }
    }
    public function testPurgeReportsOnlyACount(): void
    {
        $sink = self::sink();
        $handler = new class extends \CookieManagerKit\Command\Task\PurgeConsentLogCommandHandler {
            protected function removed(): int
            {
                return 7;
            }
        };
        $handler->setOutput($sink);
        $handler(new \CookieManagerKit\Command\Task\PurgeConsentLogCommand());

        $this->assertSame(['Removed the consent records of 7 browsers that have not made a choice within the retention period.'], $sink->lines);
    }

    public function testPurgeFailureIsAFixedSentence(): void
    {
        $handler = new class extends \CookieManagerKit\Command\Task\PurgeConsentLogCommandHandler {
            protected function removed(): int
            {
                throw new \RuntimeException('SQLSTATE secret detail');
            }
        };
        $handler->setOutput(self::sink());

        try {
            $handler(new \CookieManagerKit\Command\Task\PurgeConsentLogCommand());
            $this->fail('expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertSame(\CookieManagerKit\Command\Task\PurgeConsentLogCommandHandler::FAILED, $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public static function setUpBeforeClass(): void
    {
        // Concrete's core classes refuse to load without it, as in the other tests that touch them.
        defined('C5_EXECUTE') || define('C5_EXECUTE', true);
        require_once __DIR__ . '/Dashboard/translate.php';
    }
}
