<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use CookieManagerKit\Support\Text;
use ClassKit\Entity\Core\UpdatedEntity;

/**
 * One locale's title and description for a category.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkCategoryContents",
 *      indexes={@ORM\Index(name="idx_cmk_cc_locale", columns={"locale"})},
 *      options={"comment": "Per-locale content for consent categories"}
 * )
 */
class CategoryContent extends UpdatedEntity
{
    /**
     * @ORM\ManyToOne(targetEntity="Category", inversedBy="contents")
     * @ORM\JoinColumn(name="categoryID", referencedColumnName="id", onDelete="CASCADE", nullable=false)
     */
    protected $category;

    /**
     * @ORM\Column(type="string", length=32, nullable=false, options={"comment": "Locale ID, eg. en_GB"})
     */
    protected $locale = '';

    /**
     * @ORM\Column(type="string", length=255, nullable=false, options={"default": "", "comment": "Title (plain text)"})
     */
    protected $title = '';

    /**
     * @ORM\Column(type="text", nullable=false, options={"comment": "Description (plain text)"})
     */
    protected $description = '';

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getLocale(): string
    {
        return (string) $this->locale;
    }

    public function setLocale(string $locale): self
    {
        if (!Text::isLocale($locale)) {
            throw new \InvalidArgumentException('Invalid locale.');
        }

        $this->locale = $locale;

        return $this;
    }

    public function getTitle(): string
    {
        return (string) $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = Text::plain($title);

        return $this;
    }

    public function getDescription(): string
    {
        return (string) $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = Text::multiline($description);

        return $this;
    }
}
