<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use ClassKit\Entity\Core\BaseEntity;

/**
 * One scan: a crawl, or one admin scan-mode session. Times are UTC unix seconds.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkScanRuns",
 *      options={"comment": "Cookie Manager scan runs"}
 * )
 */
class ScanRun extends BaseEntity
{
    public const CRAWL = 'crawl';
    public const BEACON = 'beacon';
    public const RUNNING = 'running';
    public const COMPLETE = 'complete';
    public const FAILED = 'failed';

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "crawl", "comment": "crawl or beacon"})
     */
    protected $source = self::CRAWL;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "Start, UTC unix seconds"})
     */
    protected $startedAt = 0;

    /**
     * @ORM\Column(type="integer", nullable=true, options={"comment": "End, UTC unix seconds"})
     */
    protected $finishedAt;

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "running", "comment": "running, complete or failed"})
     */
    protected $status = self::RUNNING;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "Pages crawled, or reports received"})
     */
    protected $pagesScanned = 0;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "Findings first seen in this run"})
     */
    protected $newCount = 0;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "Findings that came back or went away"})
     */
    protected $changedCount = 0;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "Unclassified findings after this run"})
     */
    protected $unclassifiedCount = 0;

    /**
     * @ORM\Column(type="boolean", nullable=false, options={"default": false, "comment": "A crawl that saw every page, so unseen findings could go gone"})
     */
    protected $sawEverything = false;

    public function start(string $source, int $now): self
    {
        $this->source = in_array($source, [self::CRAWL, self::BEACON], true) ? $source : self::CRAWL;
        $this->startedAt = $now;
        $this->status = self::RUNNING;
        $this->finishedAt = null;

        return $this;
    }

    public function finish(string $status, int $now): self
    {
        $this->status = in_array($status, [self::COMPLETE, self::FAILED], true) ? $status : self::FAILED;
        $this->finishedAt = $now;

        return $this;
    }

    public function sawEverything(): bool
    {
        return (bool) $this->sawEverything;
    }

    public function setSawEverything(bool $sawEverything): self
    {
        $this->sawEverything = $sawEverything;

        return $this;
    }

    public function getSource(): string
    {
        return (string) $this->source;
    }

    public function getStatus(): string
    {
        return (string) $this->status;
    }

    public function getStartedAt(): int
    {
        return (int) $this->startedAt;
    }

    public function getFinishedAt(): ?int
    {
        return $this->finishedAt === null ? null : (int) $this->finishedAt;
    }

    public function getPagesScanned(): int
    {
        return (int) $this->pagesScanned;
    }

    public function setPagesScanned(int $pages): self
    {
        $this->pagesScanned = max(0, $pages);

        return $this;
    }

    public function getNewCount(): int
    {
        return (int) $this->newCount;
    }

    public function getChangedCount(): int
    {
        return (int) $this->changedCount;
    }

    public function getUnclassifiedCount(): int
    {
        return (int) $this->unclassifiedCount;
    }

    public function setCounts(int $new, int $changed, int $unclassified): self
    {
        $this->newCount = max(0, $new);
        $this->changedCount = max(0, $changed);
        $this->unclassifiedCount = max(0, $unclassified);

        return $this;
    }
}
