<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use ClassKit\Entity\Core\UpdatedEntity;

/**
 * One distinct thing a scan saw. Identity is (kind, name, host), stored as a fingerprint because a composite unique
 * key over those columns can exceed index-length limits. Names and hosts come from the public internet: they are
 * length-capped and control-stripped by the scanners, kept verbatim here, and encoded wherever they are shown.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkScanFindings",
 *      uniqueConstraints={@ORM\UniqueConstraint(name="uniq_cmk_finding_fingerprint", columns={"fingerprint"})},
 *      indexes={@ORM\Index(name="idx_cmk_finding_status", columns={"status"})},
 *      options={"comment": "Cookie Manager scan findings"}
 * )
 */
class ScanFinding extends UpdatedEntity
{
    private const SOURCES = ['crawl', 'beacon'];
    public const COOKIE = 'cookie';
    public const STORAGE = 'storage';
    public const SCRIPT = 'script';
    public const IFRAME = 'iframe';
    public const IMAGE = 'image';
    public const RESOURCE = 'resource';
    public const KINDS = [self::COOKIE, self::STORAGE, self::SCRIPT, self::IFRAME, self::IMAGE, self::RESOURCE];

    public const UNCLASSIFIED = 'unclassified';
    public const MATCHED = 'matched';
    public const APPROVED = 'approved';
    public const IGNORED = 'ignored';
    public const GONE = 'gone';
    public const STATUSES = [self::UNCLASSIFIED, self::MATCHED, self::APPROVED, self::IGNORED, self::GONE];

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "resource", "comment": "cookie, storage, script, iframe, image or resource"})
     */
    protected $kind = self::RESOURCE;

    /**
     * @ORM\Column(type="string", length=255, nullable=false, options={"default": "", "comment": "Cookie or storage key name; the host for other kinds"})
     */
    protected $name = '';

    /**
     * @ORM\Column(type="string", length=253, nullable=false, options={"default": "", "comment": "Host it was seen on or loaded from"})
     */
    protected $host = '';

    /**
     * @ORM\Column(type="string", length=40, nullable=false, options={"comment": "sha1 of kind, name and host"})
     */
    protected $fingerprint = '';

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "First seen, UTC unix seconds"})
     */
    protected $firstSeen = 0;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "Last seen, UTC unix seconds"})
     */
    protected $lastSeen = 0;

    /**
     * @ORM\Column(type="string", length=32, nullable=false, options={"default": "", "comment": "crawl, beacon or both"})
     */
    protected $sources = '';

    /**
     * @ORM\Column(type="string", length=512, nullable=false, options={"default": "", "comment": "A page it was found on"})
     */
    protected $examplePage = '';

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "unclassified", "comment": "Review status"})
     */
    protected $status = self::UNCLASSIFIED;

    /**
     * @ORM\Column(type="string", length=16, nullable=true, options={"comment": "Status to return to if a gone finding reappears"})
     */
    protected $restoreStatus;

    /**
     * @ORM\Column(type="string", length=32, nullable=true, options={"comment": "Suggested category handle"})
     */
    protected $suggestedCategory;

    /**
     * @ORM\Column(type="string", length=255, nullable=true, options={"comment": "Suggested vendor"})
     */
    protected $suggestedVendor;

    /**
     * @ORM\ManyToOne(targetEntity="Service")
     * @ORM\JoinColumn(name="serviceID", referencedColumnName="id", onDelete="SET NULL", nullable=true)
     */
    protected $service;

    public static function fingerprintFor(string $kind, string $name, string $host): string
    {
        return sha1($kind . "\0" . $name . "\0" . $host);
    }

    public function identify(string $kind, string $name, string $host): self
    {
        $this->kind = in_array($kind, self::KINDS, true) ? $kind : self::RESOURCE;
        $this->name = $name;
        $this->host = $host;
        $this->fingerprint = self::fingerprintFor($this->kind, $name, $host);

        return $this;
    }

    public function getKind(): string
    {
        return (string) $this->kind;
    }

    public function getName(): string
    {
        return (string) $this->name;
    }

    public function getHost(): string
    {
        return (string) $this->host;
    }

    public function getFingerprint(): string
    {
        return (string) $this->fingerprint;
    }

    public function getFirstSeen(): int
    {
        return (int) $this->firstSeen;
    }

    public function setFirstSeen(int $time): self
    {
        $this->firstSeen = $time;

        return $this;
    }

    public function getLastSeen(): int
    {
        return (int) $this->lastSeen;
    }

    public function setLastSeen(int $time): self
    {
        $this->lastSeen = $time;

        return $this;
    }

    public function getExamplePage(): string
    {
        return (string) $this->examplePage;
    }

    public function setExamplePage(string $page): self
    {
        $this->examplePage = mb_substr($page, 0, 512, 'UTF-8');

        return $this;
    }

    public function getStatus(): string
    {
        return (string) $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = in_array($status, self::STATUSES, true) ? $status : self::UNCLASSIFIED;

        return $this;
    }

    public function getSuggestedCategory(): ?string
    {
        return $this->suggestedCategory === null ? null : (string) $this->suggestedCategory;
    }

    public function getSuggestedVendor(): ?string
    {
        return $this->suggestedVendor === null ? null : (string) $this->suggestedVendor;
    }

    public function setSuggestion(?string $category, ?string $vendor): self
    {
        $this->suggestedCategory = $category;
        $this->suggestedVendor = $vendor;

        return $this;
    }

    public function getService(): ?Service
    {
        return $this->service instanceof Service ? $this->service : null;
    }

    public function setService(?Service $service): self
    {
        $this->service = $service;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function sources(): array
    {
        return $this->sources === '' ? [] : explode(',', (string) $this->sources);
    }

    public function hasSource(string $source): bool
    {
        return in_array($source, $this->sources(), true);
    }

    public function addSource(string $source): self
    {
        if (in_array($source, self::SOURCES, true) && !$this->hasSource($source)) {
            $this->sources = implode(',', [...$this->sources(), $source]);
        }

        return $this;
    }

    public function removeSource(string $source): self
    {
        $this->sources = implode(',', array_values(array_filter($this->sources(), static fn(string $have): bool => $have !== $source)));

        return $this;
    }

    public function isReviewable(): bool
    {
        return in_array($this->getStatus(), [self::MATCHED, self::UNCLASSIFIED], true);
    }

    public function markGone(): self
    {
        if ($this->getStatus() !== self::GONE) {
            $this->restoreStatus = $this->getStatus();
            $this->status = self::GONE;
        }

        return $this;
    }

    public function restore(): self
    {
        if ($this->getStatus() === self::GONE) {
            $back = (string) $this->restoreStatus;
            $this->status = in_array($back, self::STATUSES, true) && $back !== self::GONE ? $back : self::UNCLASSIFIED;
            $this->restoreStatus = null;
        }

        return $this;
    }
}
