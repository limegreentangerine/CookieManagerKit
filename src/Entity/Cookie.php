<?php

namespace CookieManagerKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use CookieManagerKit\Support\Text;
use ClassKit\Entity\Core\UpdatedEntity;
use Doctrine\Common\Collections\{ArrayCollection, Collection};

/**
 * A cookie a service sets. Listed to visitors in the declaration block.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="cmkCookies",
 *      uniqueConstraints={@ORM\UniqueConstraint(name="uniq_cmk_ck_service_name", columns={"serviceID","name"})},
 *      options={"comment": "Cookies set by services, listed in the declaration"}
 * )
 */
class Cookie extends UpdatedEntity
{
    public const MAX_DAYS = 3650;
    public const MAX_NAME = 128;

    /**
     * @ORM\ManyToOne(targetEntity="Service", inversedBy="cookies")
     * @ORM\JoinColumn(name="serviceID", referencedColumnName="id", onDelete="CASCADE", nullable=false)
     */
    protected $service;

    /**
     * @ORM\Column(type="string", length=128, nullable=false, options={"default": "", "comment": "Cookie name"})
     */
    protected $name = '';

    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 0, "comment": "Lifetime in days, 0 = session"})
     */
    protected $durationDays = 0;

    /**
     * @ORM\OneToMany(targetEntity="CookieContent", mappedBy="cookie", cascade={"persist", "remove"}, orphanRemoval=true)
     */
    protected $contents;

    public function __construct()
    {
        parent::__construct();

        $this->contents = new ArrayCollection();
    }

    public static function clampDays(mixed $value): int
    {
        if (is_string($value) && preg_match('/^\d{1,9}\z/', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) ? max(0, min(self::MAX_DAYS, $value)) : 0;
    }

    public function getService(): ?Service
    {
        return $this->service;
    }

    public function setService(Service $service): self
    {
        $this->service = $service;

        return $this;
    }

    public function getName(): string
    {
        return (string) $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = Text::plain($name, self::MAX_NAME);

        return $this;
    }

    public function getDurationDays(): int
    {
        return (int) $this->durationDays;
    }

    public function setDurationDays(mixed $days): self
    {
        $this->durationDays = self::clampDays($days);

        return $this;
    }

    public function getContents(): Collection
    {
        return $this->contents;
    }

    public function getContent(string $locale): ?CookieContent
    {
        foreach ($this->contents as $content) {
            if ($content->getLocale() === $locale) {
                return $content;
            }
        }

        return null;
    }

    public function setContent(string $locale, string $purpose): CookieContent
    {
        $content = $this->getContent($locale);

        if ($content === null) {
            $content = (new CookieContent())->setLocale($locale)->setCookie($this);
            $this->contents->add($content);
        }

        $content->setPurpose($purpose);

        return $content;
    }
}
