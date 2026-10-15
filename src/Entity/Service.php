<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use CookieManagerKit\Support\Text;
use ClassKit\Entity\Core\UpdatedEntity;
use Doctrine\Common\Collections\{ArrayCollection, Collection};

/**
 * A third-party service (vendor tool) that belongs to one consent category. Phase 1 uses it for display; later
 * phases use its domains for scanning and embed blocking.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkServices",
 *      options={"comment": "Third-party services listed under consent categories"}
 * )
 */
class Service extends UpdatedEntity
{
    /**
     * @ORM\ManyToOne(targetEntity="Category", inversedBy="services")
     * @ORM\JoinColumn(name="categoryID", referencedColumnName="id", onDelete="RESTRICT", nullable=false)
     */
    protected $category;

    /**
     * @ORM\Column(type="string", length=255, nullable=false, options={"default": "", "comment": "Service name"})
     */
    protected $name = '';

    /**
     * @ORM\Column(type="string", length=255, nullable=false, options={"default": "", "comment": "Vendor / provider"})
     */
    protected $vendor = '';

    /**
     * @ORM\Column(type="text", nullable=false, options={"comment": "Domains, one per line"})
     */
    protected $domains = '';

    /**
     * @ORM\Column(type="boolean", nullable=false, options={"default": true, "comment": "Listed to visitors"})
     */
    protected $active = true;

    /**
     * @ORM\OneToMany(targetEntity="ServiceContent", mappedBy="service", cascade={"persist", "remove"}, orphanRemoval=true)
     */
    protected $contents;

    public function __construct()
    {
        parent::__construct();

        $this->contents = new ArrayCollection();
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getName(): string
    {
        return (string) $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = Text::plain($name);

        return $this;
    }

    public function getVendor(): string
    {
        return (string) $this->vendor;
    }

    public function setVendor(?string $vendor): self
    {
        $this->vendor = Text::plain($vendor);

        return $this;
    }

    /**
     * Keeps only plain hostnames (optionally with a leading "*."), lower-cased, one per line.
     */
    public function setDomainsText(?string $text): self
    {
        $this->domains = implode("\n", self::parseDomains((string) $text));

        return $this;
    }

    public function getDomainsText(): string
    {
        return (string) $this->domains;
    }

    /**
     * @return list<string>
     */
    public function getDomains(): array
    {
        return $this->domains === '' ? [] : explode("\n", (string) $this->domains);
    }

    /**
     * @return list<string>
     */
    public static function parseDomains(string $text): array
    {
        $domains = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = strtolower(trim($line));

            if (preg_match('/^(?:\*\.)?(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/', $line) === 1) {
                $domains[] = $line;
            }
        }

        return array_values(array_unique($domains));
    }

    public function isActive(): bool
    {
        return (bool) $this->active;
    }

    public function setActive($value): self
    {
        $this->active = (bool) $value;

        return $this;
    }

    public function getContents(): Collection
    {
        return $this->contents;
    }

    public function getContent(string $locale): ?ServiceContent
    {
        foreach ($this->contents as $content) {
            if ($content->getLocale() === $locale) {
                return $content;
            }
        }

        return null;
    }

    public function setContent(string $locale, string $description): ServiceContent
    {
        $content = $this->getContent($locale);

        if ($content === null) {
            $content = (new ServiceContent())->setLocale($locale)->setService($this);
            $this->contents->add($content);
        }

        $content->setDescription($description);

        return $content;
    }
}
