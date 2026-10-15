<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use CookieManagerKit\Support\Text;
use ClassKit\Entity\Core\UpdatedEntity;
use Doctrine\Common\Collections\{ArrayCollection, Collection};

/**
 * A consent category (necessary, preferences, analytics, marketing, or a custom one). The necessary category is
 * always on and can never be removed.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkCategories",
 *      uniqueConstraints={@ORM\UniqueConstraint(name="uniq_cmk_category_handle", columns={"handle"})},
 *      options={"comment": "Consent categories"}
 * )
 */
class Category extends UpdatedEntity
{
    public const NECESSARY = 'necessary';

    /**
     * @ORM\Column(type="string", length=32, nullable=false, options={"comment": "Unique slug, immutable once set"})
     */
    protected $handle = '';

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "Display order"})
     */
    protected $sortOrder = 0;

    /**
     * @ORM\Column(type="boolean", nullable=false, options={"default": false, "comment": "Always on, cannot be refused or deleted"})
     */
    protected $necessary = false;

    /**
     * @ORM\OneToMany(targetEntity="CategoryContent", mappedBy="category", cascade={"persist", "remove"}, orphanRemoval=true)
     */
    protected $contents;

    /**
     * @ORM\OneToMany(targetEntity="Service", mappedBy="category")
     */
    protected $services;

    public function __construct()
    {
        parent::__construct();

        $this->contents = new ArrayCollection();
        $this->services = new ArrayCollection();
    }

    public function getHandle(): string
    {
        return (string) $this->handle;
    }

    public function setHandle(string $handle): self
    {
        if ($this->handle !== '') {
            throw new \LogicException('A category handle cannot be changed.');
        }

        if (!Text::isHandle($handle)) {
            throw new \InvalidArgumentException('Invalid category handle.');
        }

        $this->handle = $handle;

        return $this;
    }

    public function getSortOrder(): int
    {
        return (int) $this->sortOrder;
    }

    public function setSortOrder($value): self
    {
        $this->sortOrder = max(0, min(9999, (int) $value));

        return $this;
    }

    public function isNecessary(): bool
    {
        return (bool) $this->necessary;
    }

    public function setNecessary(bool $necessary): self
    {
        $this->necessary = $necessary;

        return $this;
    }

    public function getContents(): Collection
    {
        return $this->contents;
    }

    public function getContent(string $locale): ?CategoryContent
    {
        foreach ($this->contents as $content) {
            if ($content->getLocale() === $locale) {
                return $content;
            }
        }

        return null;
    }

    public function setContent(string $locale, string $title, string $description): CategoryContent
    {
        $content = $this->getContent($locale);

        if ($content === null) {
            $content = (new CategoryContent())->setLocale($locale)->setCategory($this);
            $this->contents->add($content);
        }

        $content->setTitle($title)->setDescription($description);

        return $content;
    }

    public function getServices(): Collection
    {
        return $this->services;
    }

    public function canBeDeleted(): bool
    {
        return !$this->isNecessary() && $this->services->count() === 0;
    }
}
