<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use CookieManagerKit\Support\Text;
use ClassKit\Entity\Core\BaseEntity;

/**
 * One known cookie, storage key or host pattern and the category it usually belongs to. `origin` decides
 * precedence: override (an admin's choice) beats refreshed (downloaded) beats bundled (shipped with the package).
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkClassificationEntries",
 *      indexes={@ORM\Index(name="idx_cmk_classification_origin", columns={"origin"})},
 *      options={"comment": "Cookie Manager classification data"}
 * )
 */
class ClassificationEntry extends BaseEntity
{
    public const COOKIE = 'cookie';
    public const STORAGE = 'storage';
    public const HOST = 'host';
    public const BUNDLED = 'bundled';
    public const REFRESHED = 'refreshed';
    public const OVERRIDE = 'override';

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "cookie", "comment": "cookie, storage or host"})
     */
    protected $kind = self::COOKIE;

    /**
     * @ORM\Column(type="string", length=128, nullable=false, options={"default": "", "comment": "Exact value, or a pattern with *"})
     */
    protected $pattern = '';

    /**
     * @ORM\Column(type="string", length=32, nullable=false, options={"default": "", "comment": "Category handle"})
     */
    protected $categoryHandle = '';

    /**
     * @ORM\Column(type="string", length=255, nullable=false, options={"default": "", "comment": "Vendor"})
     */
    protected $vendor = '';

    /**
     * @ORM\Column(type="string", length=500, nullable=false, options={"default": "", "comment": "What it is for"})
     */
    protected $description = '';

    /**
     * @ORM\Column(type="string", length=64, nullable=false, options={"default": "", "comment": "How long it lasts"})
     */
    protected $duration = '';

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"default": "bundled", "comment": "bundled, refreshed or override"})
     */
    protected $origin = self::BUNDLED;

    public function define(string $kind, string $pattern, string $category, string $vendor, string $description, string $duration, string $origin): self
    {
        $this->kind = in_array($kind, [self::COOKIE, self::STORAGE, self::HOST], true) ? $kind : self::COOKIE;
        $this->pattern = mb_substr($pattern, 0, 128, 'UTF-8');
        $this->categoryHandle = Text::isHandle($category) ? $category : '';
        $this->vendor = Text::plain($vendor);
        $this->description = Text::plain($description, 500);
        $this->duration = Text::plain($duration, 64);
        $this->origin = in_array($origin, [self::BUNDLED, self::REFRESHED, self::OVERRIDE], true) ? $origin : self::BUNDLED;

        return $this;
    }

    public function getKind(): string
    {
        return (string) $this->kind;
    }

    public function getPattern(): string
    {
        return (string) $this->pattern;
    }

    public function getCategoryHandle(): string
    {
        return (string) $this->categoryHandle;
    }

    public function getVendor(): string
    {
        return (string) $this->vendor;
    }

    public function getDescription(): string
    {
        return (string) $this->description;
    }

    public function getDuration(): string
    {
        return (string) $this->duration;
    }

    public function getOrigin(): string
    {
        return (string) $this->origin;
    }
}
